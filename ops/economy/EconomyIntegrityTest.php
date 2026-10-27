<?php

use App\Models\User;
use App\Models\AdminCommand;
use App\Filament\Pages\StaffGrants;
use App\Services\AdminCommandService;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\RewardWriter;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\EvaluationSurface;
use App\Support\Economy\StateRevision;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\Fixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/../rewards/Fixture.php';

final class EconomyIntegrityTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $player;
    private App\Models\Character $character;
    public function createApplication()
    {
        $app=require dirname(__DIR__,2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp():void
    {
        parent::setUp(); Fixture::guard();
        $this->admin=Fixture::actor(99); $this->player=Fixture::actor(); $this->character=Fixture::character($this->player);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
    private function lines(int $quantity=10):array { return [['type'=>'tokens','key'=>'','quantity'=>$quantity]]; }
    private function grant(?array $lines=null,?string $key=null,?int $characterId=null):array
    {
        return app(StaffGrantService::class)->grant($this->admin,$characterId??$this->character->id,$lines??$this->lines(),'QA approved compensation',$key??(string)Str::uuid());
    }
    private function rejected(callable $operation):void
    {
        try { $operation(); $this->fail('Expected validation rejection'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
    }
    private function status(int $status,callable $operation):void
    {
        try { $operation(); $this->fail('Expected HTTP exception'); }
        catch (HttpExceptionInterface $e) { $this->assertSame($status,$e->getStatusCode()); }
    }
    private function snapshot():array { return app(ClientStateService::class)->snapshot($this->player,$this->character->id); }
    private function save(array $payload):array { return app(ClientStateService::class)->saveEquipment($this->player,$this->character->id,$payload); }
    public function test_staff_and_portal_share_receipts_ledger_balances_and_revisions():void
    {
        $campaign=Fixture::campaign($this->admin);
        app(RewardService::class)->starter($this->player,$this->character->id,$campaign,'default',(string)Str::uuid());
        $staff=$this->grant([['type'=>'tokens','key'=>'','quantity'=>10],['type'=>'gold','key'=>'','quantity'=>20]]);
        $this->assertSame(5010,(int)$this->player->fresh()->tokens); $this->assertSame(2520,(int)$this->character->fresh()->gold);
        $this->assertSame(2,StateRevision::current($this->player->id));
        $this->assertDatabaseHas('nl_claims',['id'=>$staff['id'],'campaign_id'=>null,'user_id'=>$this->player->id,'actor_id'=>$this->admin->id,'source'=>'staff_grant']);
        $this->assertSame(2,DB::table('nl_reward_ledger')->where('claim_id',$staff['id'])->count());
        $this->actingAs($this->player)->get('/portal/home')->assertOk()->assertSee('QA approved compensation');
    }
    public function test_identical_staff_retry_returns_original_without_revision_or_supply_change():void
    {
        $key=(string)Str::uuid(); $first=$this->grant(null,$key); $again=$this->grant(null,$key);
        $this->assertTrue($again['replayed']); $this->assertSame($first['id'],$again['id']);
        $this->assertSame(10,(int)$this->player->fresh()->tokens); $this->assertSame(1,StateRevision::current($this->player->id));
        $this->assertSame(1,DB::table('nl_claims')->where('user_id',$this->player->id)->count());
    }
    public function test_staff_key_conflicts_across_recipients_and_changed_payloads():void
    {
        $key=(string)Str::uuid(); $this->grant(null,$key); $other=Fixture::actor(); $otherChar=Fixture::character($other);
        $this->rejected(fn()=> $this->grant(null,$key,$otherChar->id)); $this->rejected(fn()=> $this->grant($this->lines(11),$key));
        $this->assertSame(0,(int)$other->fresh()->tokens); $this->assertSame(1,DB::table('nl_claims')->where('actor_id',$this->admin->id)->count());
    }
    public function test_role_is_reloaded_from_database_not_trusted_from_passed_model():void
    {
        $this->status(403,fn()=> app(StaffGrantService::class)->grant($this->player,$this->character->id,$this->lines(),'Unauthorized attempt',(string)Str::uuid()));
        DB::table('users')->where('id',$this->admin->id)->update(['account_type'=>0]); $this->assertSame(99,(int)$this->admin->account_type);
        $this->status(403,fn()=> $this->grant()); $this->assertSame(0,(int)$this->player->fresh()->tokens);
    }
    public function test_invalid_amount_reason_key_and_unknown_fields_do_not_write():void
    {
        foreach ([0,-1,100000001] as $amount) { $this->rejected(fn()=> $this->grant($this->lines($amount))); }
        $this->rejected(fn()=> app(StaffGrantService::class)->grant($this->admin,$this->character->id,$this->lines(),'short',(string)Str::uuid()));
        $this->rejected(fn()=> $this->grant(null,'not-a-uuid'));
        $this->rejected(fn()=> $this->grant([['type'=>'tokens','key'=>'','quantity'=>10,'actor_id'=>$this->admin->id]]));
        $this->rejected(fn()=> $this->grant(array_merge($this->lines(),$this->lines())));
        $this->assertSame(0,DB::table('nl_claims')->where('user_id',$this->player->id)->count()); $this->assertSame(0,StateRevision::current($this->player->id));
    }
    public function test_portal_cannot_reuse_reserved_staff_namespace():void
    {
        $key=(string)Str::uuid(); $this->grant(null,$key); $campaign=Fixture::campaign($this->admin);
        $this->rejected(fn()=> app(RewardService::class)->starter($this->player,$this->character->id,$campaign,'default','staff:'.$this->admin->id.':'.$key));
        $this->assertSame(10,(int)$this->player->fresh()->tokens);
    }
    public function test_portal_and_staff_share_global_catalog_limit():void
    {
        $content=Fixture::content($this->admin,'item',2); $catalogId=$content['id']; unset($content['id']); $campaign=Fixture::campaign($this->admin,[$content]);
        app(RewardService::class)->starter($this->player,$this->character->id,$campaign,'default',(string)Str::uuid());
        $this->grant([$content]); $this->rejected(fn()=> $this->grant([$content]));
        $this->assertSame(2,(int)DB::table('nl_catalog')->where('id',$catalogId)->value('issued'));
        $this->assertSame(2,(int)DB::table('character_items')->where('character_id',$this->character->id)->where('item_id',$content['key'])->value('quantity'));
        $this->assertSame(2,(int)DB::table('nl_reward_ledger')->where('catalog_id',$catalogId)->sum('quantity'));
    }
    public function test_disabled_registry_and_duplicate_skill_rollback_entire_staff_pack():void
    {
        $content=Fixture::content($this->admin,'skill',5); $catalogId=$content['id']; unset($content['id']); $this->grant([$content]);
        $this->rejected(fn()=> $this->grant(array_merge($this->lines(),[$content]))); $this->assertSame(0,(int)$this->player->fresh()->tokens);
        DB::table('nl_catalog')->where('id',$catalogId)->update(['enabled'=>false]); $other=Fixture::character($this->player);
        $this->rejected(fn()=> $this->grant([$content],null,$other->id)); $this->assertSame(1,StateRevision::current($this->player->id));
    }
    public function test_fault_after_first_credit_rolls_back_all_writes():void
    {
        $writer=new class extends RewardWriter {
            public function apply(object $a,object $c,array $l,?object $catalog):array {
                parent::apply($a,$c,$l,$catalog); throw new RuntimeException('QA deliberate failure after write');
            }
        };
        try { (new StaffGrantService($writer))->grant($this->admin,$this->character->id,$this->lines(),'QA rollback fault',(string)Str::uuid()); $this->fail('Fault not triggered'); }
        catch (RuntimeException $e) { $this->assertSame('QA deliberate failure after write',$e->getMessage()); }
        $this->assertSame(0,(int)$this->player->fresh()->tokens); $this->assertSame(0,StateRevision::current($this->player->id));
        $this->assertSame(0,DB::table('nl_claims')->where('user_id',$this->player->id)->count());
    }
    public function test_late_ledger_failure_rolls_back_grant_receipt_and_revision():void
    {
        // Inject after the actual ledger INSERT, without DDL that would implicitly commit MySQL transactions.
        DB::listen(function (Illuminate\Database\Events\QueryExecuted $event):void {
            if (str_starts_with(strtolower(ltrim($event->sql)),'insert into `nl_reward_ledger`')) {
                throw new RuntimeException('QA late ledger failure');
            }
        });
        try { $this->grant(); $this->fail('Expected late fault'); }
        catch (RuntimeException $e) { $this->assertSame('QA late ledger failure',$e->getMessage()); }
        $this->assertSame(0,(int)$this->player->fresh()->tokens); $this->assertSame(0,StateRevision::current($this->player->id));
        $this->assertSame(0,DB::table('nl_claims')->where('user_id',$this->player->id)->count());
        $this->assertSame(0,DB::table('nl_reward_ledger')->count());
    }
    public function test_balance_and_revision_overflow_fail_without_receipt():void
    {
        DB::table('users')->where('id',$this->player->id)->update(['tokens'=>2147483647]); $this->rejected(fn()=> $this->grant());
        DB::table('users')->where('id',$this->player->id)->update(['tokens'=>0]);
        DB::table('nl_account_revisions')->insert(['user_id'=>$this->player->id,'revision'=>4294967295]); $this->rejected(fn()=> $this->grant());
        $this->assertSame(0,(int)$this->player->fresh()->tokens); $this->assertSame(0,DB::table('nl_claims')->where('user_id',$this->player->id)->count());
    }
    public function test_stale_state_after_portal_reward_conflicts_without_overwriting_reward():void
    {
        $old=$this->snapshot(); $campaign=Fixture::campaign($this->admin);
        app(RewardService::class)->starter($this->player,$this->character->id,$campaign,'default',(string)Str::uuid());
        $this->status(409,fn()=> $this->save(['revision'=>$old['revision'],'equipment'=>['weapon'=>'']]));
        $fresh=$this->snapshot(); $this->assertSame(5000,$fresh['tokens']); $this->assertSame(2500,$fresh['gold']); $this->assertSame(1,$fresh['revision']);
    }
    public function test_another_character_grant_invalidates_account_wide_snapshot():void
    {
        $old=$this->snapshot(); $other=Fixture::character($this->player); $this->grant(null,null,$other->id);
        $this->status(409,fn()=> $this->save(['revision'=>$old['revision'],'equipment'=>['weapon'=>'']])); $this->assertSame(10,$this->snapshot()['tokens']);
    }
    public function test_client_cannot_send_economy_or_role_snapshot_and_foreign_owner_is_rejected():void
    {
        foreach (['tokens','gold','inventory','items','skills','pets','account_type','user_id'] as $key) {
            $this->rejected(fn()=> $this->save(['revision'=>0,'equipment'=>['weapon'=>''],$key=>999]));
        }
        $other=Fixture::actor(); $this->status(404,fn()=> app(ClientStateService::class)->snapshot($other,$this->character->id));
        $this->status(404,fn()=> app(ClientStateService::class)->saveEquipment($other,$this->character->id,['revision'=>0,'equipment'=>['weapon'=>'']]));
        $this->assertSame(0,$this->snapshot()['revision']);
    }
    public function test_fresh_equipment_save_checks_ownership_slot_and_preserves_balances():void
    {
        $content=Fixture::content($this->admin,'item',20); unset($content['id']);
        DB::table('items')->where('item_id',$content['key'])->update(['category'=>'weapon','level'=>1,'premium'=>false]);
        $this->rejected(fn()=> $this->save(['revision'=>0,'equipment'=>['weapon'=>$content['key']]]));
        $this->grant(array_merge($this->lines(),[$content])); $current=$this->snapshot();
        $this->rejected(fn()=> $this->save(['revision'=>$current['revision'],'equipment'=>['back'=>$content['key']]]));
        $saved=$this->save(['revision'=>$current['revision'],'equipment'=>['weapon'=>$content['key']]]);
        $this->assertSame($content['key'],$saved['equipment']['weapon']); $this->assertSame(10,$saved['tokens']); $this->assertSame(0,$saved['gold']); $this->assertSame(2,$saved['revision']);
        $this->assertDatabaseHas('nl_operations_audit',['actor_id'=>$this->player->id,'action'=>'equipment.save']);
        $this->status(409,fn()=> $this->save(['revision'=>$current['revision'],'equipment'=>['weapon'=>'']]));
    }
    public function test_equipment_level_premium_and_legacy_duplicates_are_rejected():void
    {
        $content=Fixture::content($this->admin,'item',20); unset($content['id']); $this->grant([$content]);
        DB::table('items')->where('item_id',$content['key'])->update(['category'=>'weapon','level'=>999]);
        $payload=['revision'=>1,'equipment'=>['weapon'=>$content['key']]]; $this->rejected(fn()=> $this->save($payload));
        DB::table('items')->where('item_id',$content['key'])->update(['level'=>1,'premium'=>true]); $this->rejected(fn()=> $this->save($payload));
        DB::table('items')->where('item_id',$content['key'])->update(['premium'=>false]);
        DB::table('character_items')->insert(['character_id'=>$this->character->id,'item_id'=>$content['key'],'quantity'=>1,'category'=>'weapon','created_at'=>now(),'updated_at'=>now()]);
        $this->rejected(fn()=> $this->save($payload)); $this->assertSame(2,DB::table('character_items')->where('character_id',$this->character->id)->count());
        $this->assertSame(1,StateRevision::current($this->player->id));
    }
    public function test_legacy_command_and_http_paths_do_not_mutate_rewards():void
    {
        $this->grant(); $command=new AdminCommand(['command_type'=>'add_tokens','params'=>['amount'=>999]]);
        $this->assertFalse(app(AdminCommandService::class)->execute($command,$this->character)['success']); $this->actingAs($this->admin);
        foreach (['/amf','/gateway.php','/clan/auth/login','/crew/auth/login'] as $path) { $this->post($path,['gold'=>0,'tokens'=>0,'inventory'=>[]])->assertNotFound(); }
        foreach (['/admin/users','/admin/characters','/admin/admin-commands','/admin/world-chat'] as $path) { $this->get($path)->assertNotFound(); }
        $this->assertSame(10,(int)$this->player->fresh()->tokens); $this->assertSame(1,DB::table('nl_claims')->where('user_id',$this->player->id)->count());
    }
    public function test_actual_livewire_mount_rejects_quarantined_component():void
    {
        $this->actingAs($this->admin); Livewire::test(App\Filament\Pages\WorldChat::class)->assertForbidden();
        $hook=new App\Support\Economy\EnforceEvaluationSurface(); $hook->setComponent(new App\Filament\Pages\WorldChat());
        $this->status(403,fn()=> $hook->callBoot());
    }
    public function test_actual_console_event_blocks_legacy_app_command():void
    {
        $commands=app(Illuminate\Contracts\Console\Kernel::class)->all(); $found=false;
        foreach ($commands as $name=>$command) {
            if (get_class($command)!==App\Console\Commands\FixCharacterItems::class) { continue; } $found=true;
            try { Illuminate\Support\Facades\Artisan::call($name); $this->fail('Legacy command unexpectedly ran'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('dikarantina',$e->getMessage()); }
            break;
        }
        $this->assertTrue($found,'Legacy command must remain discoverable for explicit denial');
        $this->assertTrue(EvaluationSurface::commandAllowed(App\Console\Commands\CreateEvaluationAdmin::class));
    }
    public function test_staff_livewire_uses_authenticated_actor_and_requires_explicit_new_grant():void
    {
        $this->actingAs($this->admin);
        $page=Livewire::test(StaffGrants::class)->set('characterId',(string)$this->character->id)->set('quantity','7')->set('reason','QA approved compensation')->call('grant')->assertHasNoErrors();
        $receipt=$page->get('receiptId'); $key=$page->get('requestKey'); $this->assertNotEmpty($receipt);
        $page->call('grant')->assertSet('receiptId',$receipt)->assertSet('requestKey',$key); $this->assertSame(7,(int)$this->player->fresh()->tokens);
        $page->call('newGrant')->assertSet('receiptId',''); $this->assertNotSame($key,$page->get('requestKey'));
        $this->actingAs($this->player)->get('/admin/staff-grants')->assertForbidden();
    }
    public function test_services_are_not_enabled_in_production():void
    {
        $this->app->instance('env','production'); $this->status(403,fn()=> $this->grant()); $this->status(403,fn()=> $this->snapshot());
    }
}
