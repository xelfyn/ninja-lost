<?php

use App\Models\User;
use App\Player\Pages\PlayerHome;
use App\Services\Rewards\RewardManagement;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\RewardWriter;
use App\Support\Rewards\RewardRules;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\Fixture;

require_once __DIR__.'/Fixture.php';

final class RewardRuntimeTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $player;
    private App\Models\Character $character;
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard();
        $this->admin = Fixture::actor(99); $this->player = Fixture::actor();
        $this->character = Fixture::character($this->player);
        Filament::setCurrentPanel(Filament::getPanel('player'));
    }
    private function claim(int $campaign, ?string $key = null, string $choice = 'default'): array
    {
        return app(RewardService::class)->starter($this->player, $this->character->id, $campaign, $choice, $key ?? (string) Str::uuid());
    }
    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Expected rejected transaction.'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
    }
    public function test_player_pages_require_login_and_admin_stays_private(): void
    {
        $this->get('/portal/home')->assertRedirect('/portal/login');
        $this->actingAs($this->player)->get('/portal/home')->assertOk()->assertSee('Portal Pemain');
        $this->get('/portal/catalog')->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/reward-campaigns')->assertForbidden();
        $this->get('/admin/content-registry')->assertForbidden();
    }
    public function test_player_login_regenerates_session_and_logout_ends_it(): void
    {
        $password = bin2hex(random_bytes(24)); $this->player->password = $password; $this->player->save();
        session()->start(); $before = session()->getId();
        Livewire::test(Filament\Auth\Pages\Login::class)->fillForm(['email'=>$this->player->email, 'password'=>$password])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->player);
        $this->assertNotSame($before, session()->getId());
        $this->post('/portal/logout')->assertRedirect();
        $this->assertGuest();
    }
    public function test_wrong_password_and_login_throttling(): void
    {
        $password = bin2hex(random_bytes(24)); $this->player->password = $password; $this->player->save();
        for ($i=0; $i<5; $i++) {
            Livewire::test(Filament\Auth\Pages\Login::class)->fillForm(['email'=>$this->player->email, 'password'=>'wrong-password'])->call('authenticate');
            $this->assertGuest();
        }
        Livewire::test(Filament\Auth\Pages\Login::class)->fillForm(['email'=>$this->player->email, 'password'=>$password])->call('authenticate');
        $this->assertGuest('Sixth attempt must be rate limited even with correct credentials.');
    }
    public function test_normal_claim_updates_shared_account_and_character_balances(): void
    {
        $id = Fixture::campaign($this->admin); $result = $this->claim($id);
        $this->assertFalse($result['replayed']);
        $this->assertSame(5000, (int) $this->player->fresh()->tokens);
        $this->assertSame(2500, (int) $this->character->fresh()->gold);
        $this->assertDatabaseHas('nl_claims', ['id'=>$result['id'], 'user_id'=>$this->player->id, 'character_id'=>$this->character->id]);
        $this->assertSame(2, DB::table('nl_reward_ledger')->where('claim_id', $result['id'])->count());
    }
    public function test_idempotent_retry_returns_original_even_after_pause(): void
    {
        $id=Fixture::campaign($this->admin); $key=(string)Str::uuid(); $first=$this->claim($id,$key);
        app(RewardManagement::class)->setCampaignStatus($this->admin,$id,'paused');
        $second=$this->claim($id,$key);
        $this->assertTrue($second['replayed']); $this->assertSame($first['id'],$second['id']);
        $this->assertSame(5000,(int)$this->player->fresh()->tokens);
        $this->assertSame(1,DB::table('nl_claims')->where('campaign_id',$id)->count());
    }
    public function test_same_key_different_payload_is_rejected(): void
    {
        $id=Fixture::campaign($this->admin); $key=(string)Str::uuid(); $this->claim($id,$key);
        $this->rejected(fn()=> $this->claim($id,$key,'other'));
        $this->assertSame(5000,(int)$this->player->fresh()->tokens);
    }
    public function test_new_key_and_other_character_do_not_bypass_account_claim(): void
    {
        $id=Fixture::campaign($this->admin); $this->claim($id);
        $other=Fixture::character($this->player);
        $this->rejected(fn()=>app(RewardService::class)->starter($this->player,$other->id,$id,'default',(string)Str::uuid()));
        $this->assertSame(0,(int)$other->fresh()->gold);
    }
    public function test_foreign_character_is_denied_without_any_grant(): void
    {
        $other=Fixture::character(Fixture::actor()); $id=Fixture::campaign($this->admin);
        try { app(RewardService::class)->starter($this->player,$other->id,$id,'default',(string)Str::uuid()); $this->fail(); }
        catch(Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        $this->assertSame(0,DB::table('nl_claims')->where('campaign_id',$id)->count());
        $this->assertSame(0,(int)$this->player->fresh()->tokens);
    }
    public function test_portal_never_lists_another_accounts_character(): void
    {
        $other=Fixture::character(Fixture::actor());
        $this->actingAs($this->player)->get('/portal/home')->assertSee($this->character->name)->assertDontSee($other->name);
        Livewire::test(PlayerHome::class)->set('characterId',(string)$other->id)->assertStatus(404);
    }
    public function test_non_admin_cannot_create_or_activate_campaigns(): void
    {
        $id=Fixture::campaign($this->admin,[],[],false);
        try { app(RewardManagement::class)->setCampaignStatus($this->player,$id,'active'); $this->fail(); }
        catch(Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'status'=>'draft']);
        $this->assertSame(0,(int)$this->player->fresh()->account_type);
    }
    public function test_invalid_choice_future_expired_and_paused_are_rejected(): void
    {
        $id=Fixture::campaign($this->admin); $this->rejected(fn()=> $this->claim($id,null,'not-allowed'));
        app(RewardManagement::class)->setCampaignStatus($this->admin,$id,'paused'); $this->rejected(fn()=> $this->claim($id));
        $future=Fixture::campaign($this->admin,[],['starts_at'=>now()->addHour()->toIso8601String()]); $this->rejected(fn()=> $this->claim($future));
        $expired=Fixture::campaign($this->admin,[],['starts_at'=>now()->subDays(2)->toIso8601String(),'ends_at'=>now()->subDay()->toIso8601String()],false);
        $this->rejected(fn()=> $this->claim($expired));
        $this->assertSame(0,(int)$this->player->fresh()->tokens);
    }
    public function test_redeem_hash_only_and_replay_used_invalid_codes(): void
    {
        $code='QA-'.strtoupper(bin2hex(random_bytes(10)));
        $id=Fixture::campaign($this->admin,[],['kind'=>'redeem','code'=>$code]);
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'code_hash'=>hash('sha256',$code)]);
        $key=(string)Str::uuid(); $service=app(RewardService::class);
        $first=$service->redeem($this->player,$this->character->id,strtolower($code),'default',$key);
        $this->assertTrue($service->redeem($this->player,$this->character->id,$code,'default',$key)['replayed']);
        $this->rejected(fn()=> $service->redeem($this->player,$this->character->id,$code,'default',(string)Str::uuid()));
        $this->rejected(fn()=> $service->redeem($this->player,$this->character->id,'INVALID-CODE-999','default',(string)Str::uuid()));
        $this->assertStringNotContainsString($code,json_encode(DB::table('nl_claims')->where('id',$first['id'])->first()));
        $this->assertStringNotContainsString($code,json_encode(DB::table('nl_operations_audit')->where('actor_id',$this->admin->id)->get()));
    }
    public function test_starter_method_cannot_bypass_redeem_code(): void
    {
        $id=Fixture::campaign($this->admin,[],['kind'=>'redeem','code'=>'QA-'.strtoupper(bin2hex(random_bytes(10)))]);
        $this->rejected(fn()=> $this->claim($id));
    }
    public function test_campaign_quota_and_global_skill_supply_across_campaigns(): void
    {
        $skill=Fixture::content($this->admin,'skill',1); unset($skill['id']);
        $one=Fixture::campaign($this->admin,[$skill]); $two=Fixture::campaign($this->admin,[$skill]);
        $this->claim($one); $other=Fixture::actor(); $char=Fixture::character($other);
        $this->rejected(fn()=> app(RewardService::class)->starter($other,$char->id,$two,'default',(string)Str::uuid()));
        $this->assertDatabaseHas('nl_catalog',['kind'=>'skill','content_key'=>$skill['key'],'issued'=>1]);
        $quota=Fixture::campaign($this->admin,[],['max_claims'=>1]); $this->claim($quota);
        $this->rejected(fn()=> app(RewardService::class)->starter($other,$char->id,$quota,'default',(string)Str::uuid()));
    }
    public function test_shared_item_skill_pet_inventory_and_disabled_registry(): void
    {
        $lines=[]; $ids=[];
        foreach(['item','skill','pet'] as $kind) { $line=Fixture::content($this->admin,$kind); $ids[]=$line['id']; unset($line['id']); $lines[]=$line; }
        $id=Fixture::campaign($this->admin,$lines); $this->claim($id);
        foreach($lines as $line) { $this->assertDatabaseHas('character_'.$line['type'].'s',['character_id'=>$this->character->id,$line['type'].'_id'=>$line['key']]); }
        app(RewardManagement::class)->setCatalogEnabled($this->admin,$ids[0],false);
        $draft=Fixture::campaign($this->admin,[$lines[0]],[],false);
        $this->rejected(fn()=>app(RewardManagement::class)->setCampaignStatus($this->admin,$draft,'active'));
    }
    public function test_existing_skill_rejects_entire_pack_including_currency(): void
    {
        $skill=Fixture::content($this->admin); unset($skill['id']); $this->claim(Fixture::campaign($this->admin,[$skill]));
        $id=Fixture::campaign($this->admin,[['type'=>'tokens','key'=>'','quantity'=>100],$skill]);
        $this->rejected(fn()=> $this->claim($id));
        $this->assertSame(0,(int)$this->player->fresh()->tokens);
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'claimed_count'=>0]);
    }
    public function test_fault_after_first_credit_rolls_back_everything(): void
    {
        $writer=new class extends RewardWriter {
            private int $calls=0;
            public function apply(object $account,object $character,array $line,?object $catalog): array {
                if (++$this->calls===2) { throw new RuntimeException('Injected test failure'); }
                return parent::apply($account,$character,$line,$catalog);
            }
        };
        $id=Fixture::campaign($this->admin);
        try { (new RewardService($writer))->starter($this->player,$this->character->id,$id,'default',(string)Str::uuid()); $this->fail(); }
        catch(RuntimeException $e) { $this->assertSame('Injected test failure',$e->getMessage()); }
        $this->assertSame(0,(int)$this->player->fresh()->tokens); $this->assertSame(0,(int)$this->character->fresh()->gold);
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'claimed_count'=>0]);
        $this->assertSame(0,DB::table('nl_claims')->where('campaign_id',$id)->count());
    }
    public function test_balance_overflow_and_negative_reward_are_rejected(): void
    {
        $this->player->tokens=RewardRules::BALANCE_MAX; $this->player->save();
        $this->rejected(fn()=> $this->claim(Fixture::campaign($this->admin)));
        $this->rejected(fn()=> Fixture::campaign($this->admin,[['type'=>'tokens','key'=>'','quantity'=>-1]]));
        $this->assertSame(0,(int)$this->character->fresh()->gold);
    }
    public function test_duplicate_reward_lines_and_unknown_payload_keys_are_rejected(): void
    {
        $line=['type'=>'tokens','key'=>'','quantity'=>1];
        $this->rejected(fn()=>RewardRules::choices([['key'=>'default','label'=>'QA','rewards'=>[$line,$line]]]));
        $line['account_type']=99;
        $this->rejected(fn()=>RewardRules::choices([['key'=>'default','label'=>'QA','rewards'=>[$line]]]));
    }
    public function test_campaign_creation_ignores_client_status_and_counter_injection(): void
    {
        $id=Fixture::campaign($this->admin,[],['status'=>'active','claimed_count'=>999],false);
        $this->assertDatabaseHas('nl_campaigns',['id'=>$id,'status'=>'draft','claimed_count'=>0]);
    }
    public function test_admin_forms_render_and_real_livewire_starter_claim(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin)->get('/admin/reward-campaigns')->assertOk();
        $this->get('/admin/content-registry')->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('player')); $this->actingAs($this->player);
        $id=Fixture::campaign($this->admin);
        Livewire::test(PlayerHome::class)->set('campaignId',(string)$id)->set('choiceKey','default')->call('claimStarter')->assertHasNoErrors()->assertNotified('Hadiah berhasil diterima');
        $this->assertSame(5000,(int)$this->player->fresh()->tokens);
    }
    public function test_no_legacy_game_endpoints_or_public_registration_opened(): void
    {
        foreach(['/amf','/gateway.php','/clan/auth/login','/crew/auth/login','/portal/register'] as $path) { $this->post($path)->assertNotFound(); }
    }
}
