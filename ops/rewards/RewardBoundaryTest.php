<?php

use App\Filament\Pages\ContentRegistry;
use App\Filament\Pages\RewardCampaigns;
use App\Player\Pages\PlayerHome;
use App\Services\Rewards\RewardManagement;
use App\Services\Rewards\RewardService;
use App\Support\Rewards\RewardClock;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use NinjaLost\QA\Fixture;

require_once __DIR__.'/Fixture.php';

final class RewardBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app=require dirname(__DIR__,2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_naive_utc_and_offset_input_do_not_inherit_legacy_timezone(): void
    {
        $original=date_default_timezone_get();
        try {
            foreach(['Asia/Kuala_Lumpur','Asia/Jakarta','UTC','America/Los_Angeles'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame('2030-01-02 15:00:00',RewardClock::parse('2030-01-02 15:00:00')->format('Y-m-d H:i:s'));
                $this->assertSame('2030-01-02 15:00:00',RewardClock::parse('2030-01-02T23:00:00+08:00')->format('Y-m-d H:i:s'));
            }
        } finally { date_default_timezone_set($original); }
    }

    public function test_start_is_inclusive_end_exclusive_and_retry_returns_original(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $user=Fixture::actor(); $char=Fixture::character($user);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-02 15:00:00','UTC'));
        $id=Fixture::campaign($admin,[],['starts_at'=>'2030-01-02 15:00:00','ends_at'=>'2030-01-02 16:00:00']);
        $key=(string)Str::uuid(); $service=app(RewardService::class);
        $receipt=$service->starter($user,$char->id,$id,'default',$key);
        $this->assertFalse($receipt['replayed']);
        $this->assertDatabaseHas('nl_claims',['id'=>$receipt['id'],'created_at'=>'2030-01-02 15:00:00']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-02 16:00:00','UTC'));
        $this->assertTrue($service->starter($user,$char->id,$id,'default',$key)['replayed']);
        $other=Fixture::actor(); $otherChar=Fixture::character($other);
        $this->expectException(ValidationException::class);
        $service->starter($other,$otherChar->id,$id,'default',(string)Str::uuid());
    }

    public function test_portal_hides_future_campaign_in_legacy_non_utc_app(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $user=Fixture::actor(); Fixture::character($user);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-02 15:00:00','UTC'));
        Fixture::campaign($admin,[],['title'=>'Future Campaign Should Be Hidden','starts_at'=>'2030-01-02 15:01:00','ends_at'=>'2030-01-02 16:00:00']);
        Fixture::campaign($admin,[],['title'=>'Current Campaign Should Be Visible','starts_at'=>'2030-01-02 14:59:00','ends_at'=>'2030-01-02 16:00:00']);
        $this->actingAs($user)->get('/portal/home')->assertOk()->assertSee('Current Campaign Should Be Visible')->assertDontSee('Future Campaign Should Be Hidden');
    }

    public function test_admin_livewire_campaign_form_saves_draft_then_activates(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $slug='qa-form-'.bin2hex(random_bytes(8));
        $start=RewardClock::now()->subMinute()->format('Y-m-d H:i:s');
        $end=RewardClock::now()->addDay()->format('Y-m-d H:i:s');
        $page=Livewire::test(RewardCampaigns::class)->fillForm([
            'slug'=>$slug,'title'=>'QA Form Campaign','kind'=>'starter','code'=>'',
            'starts_at'=>$start,'ends_at'=>$end,'max_claims'=>2,
            'choices'=>[['key'=>'default','label'=>'QA Choice','rewards'=>[['type'=>'tokens','key'=>'','quantity'=>25]]]],
        ])->call('createCampaign')->assertHasNoFormErrors()->assertHasNoErrors()->assertNotified('Draft campaign disimpan');
        $row=DB::table('nl_campaigns')->where('slug',$slug)->first();
        $this->assertNotNull($row); $this->assertSame('draft',$row->status);
        $this->assertSame($start,$row->starts_at);
        $page->call('changeStatus',$row->id,'active')->assertHasNoErrors();
        $this->assertDatabaseHas('nl_campaigns',['id'=>$row->id,'status'=>'active']);
    }

    public function test_registry_form_metadata_is_disabled_and_audited(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $key='qa_effect_'.bin2hex(random_bytes(8));
        Livewire::test(ContentRegistry::class)->fillForm([
            'kind'=>'effect','content_key'=>$key,'title'=>'QA metadata only',
            'source_url'=>'https://example.invalid/authored','source_revision'=>'qa-v1',
            'rights_basis'=>'self_authored','rights_evidence'=>'Synthetic metadata authored for tests.',
            'compatibility_note'=>'No game-client or battle implementation asserted.',
            'supply_limit'=>null,'effect_metadata'=>['note'=>'not executable'],
        ])->call('registerContent')->assertHasNoFormErrors()->assertHasNoErrors();
        $row=DB::table('nl_catalog')->where('content_key',$key)->first();
        $this->assertNotNull($row); $this->assertFalse((bool)$row->enabled);
        $this->assertDatabaseHas('nl_operations_audit',['actor_id'=>$admin->id,'action'=>'catalog.register','subject'=>'catalog:'.$row->id]);
    }

    public function test_opening_supply_counts_existing_inventory(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $user=Fixture::actor(); $char=Fixture::character($user);
        $key='nlqa_'.bin2hex(random_bytes(8));
        DB::table('items')->insert(['item_id'=>$key,'name'=>'QA Existing Item','category'=>'item']);
        DB::table('character_items')->insert(['character_id'=>$char->id,'item_id'=>$key,'quantity'=>3,'category'=>'item']);
        $input=['kind'=>'item','content_key'=>$key,'title'=>'QA Existing Item','source_url'=>'https://example.invalid/authored','source_revision'=>'qa-v1','rights_basis'=>'self_authored','rights_evidence'=>'Synthetic test fixture only.','compatibility_note'=>'Inventory opening balance test only.','supply_limit'=>5];
        $id=app(RewardManagement::class)->register($admin,$input);
        $this->assertDatabaseHas('nl_catalog',['id'=>$id,'opening_issued'=>3,'issued'=>3,'enabled'=>false]);
        $this->expectException(ValidationException::class);
        app(RewardManagement::class)->register($admin,array_replace($input,['supply_limit'=>100]));
    }

    public function test_legacy_inventory_duplicates_are_rejected_not_deleted(): void
    {
        Fixture::guard(); $admin=Fixture::actor(99); $user=Fixture::actor(); $char=Fixture::character($user);
        $line=Fixture::content($admin,'item'); unset($line['id']);
        foreach([1,2] as $quantity) { DB::table('character_items')->insert(['character_id'=>$char->id,'item_id'=>$line['key'],'quantity'=>$quantity,'category'=>'item']); }
        $id=Fixture::campaign($admin,[$line]);
        try { app(RewardService::class)->starter($user,$char->id,$id,'default',(string)Str::uuid()); $this->fail('Duplicate inventory must be rejected'); }
        catch(ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        $this->assertSame(2,DB::table('character_items')->where('character_id',$char->id)->count());
        $this->assertSame(3,(int)DB::table('character_items')->where('character_id',$char->id)->sum('quantity'));
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'claimed_count'=>0]);
    }

    public function test_portal_claim_rate_limit_and_locked_request_key(): void
    {
        Fixture::guard(); $user=Fixture::actor(); Fixture::character($user); $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('player'));
        for($i=0;$i<20;$i++) { RateLimiter::hit('nl-claim:'.$user->id,60); }
        Livewire::test(PlayerHome::class)->call('redeem')->assertHasErrors(['reward']);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PlayerHome::class)->set('requestKey','malicious-key-123456');
    }
}
