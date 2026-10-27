<?php

use App\Models\User;
use App\Models\Character;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
require_once __DIR__.'/../rewards/Fixture.php';

final class UpgradeCompatibilityTest extends TestCase
{
    public function createApplication()
    {
        $app=require dirname(__DIR__,2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    public function test_nonempty_mb02_schema_upgrades_without_changing_existing_economy_history():void
    {
        Fixture::guard();
        $this->assertSame('true',getenv('GITHUB_ACTIONS'));
        $this->assertSame('127.0.0.1',config('database.connections.mysql.host'));
        $original=config('database.connections.mysql');
        $database='nl_upgrade_'.bin2hex(random_bytes(10)); $created=false;
        try {
            // Unique disposable schema on this job's isolated MySQL service, never an existing database.
            DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true;
            config(['database.connections.mysql.database'=>$database]); DB::purge('mysql');
            $migration=base_path('database/migrations/2026_10_28_000001_add_economy_integrity.php');
            $paths=array_values(array_filter(glob(base_path('database/migrations/*.php')),fn($path)=>$path!==$migration));
            $this->assertSame(0,Artisan::call('migrate',['--path'=>$paths,'--realpath'=>true,'--force'=>true]));
            $suffix=bin2hex(random_bytes(8));
            $admin=User::create(['name'=>'QA upgrade operator','username'=>'qa_up_admin_'.$suffix,'email'=>'qa_up_admin_'.$suffix.'@example.invalid','password'=>bin2hex(random_bytes(24)),'tokens'=>0,'account_type'=>99]);
            $user=User::create(['name'=>'QA upgrade player','username'=>'qa_up_player_'.$suffix,'email'=>'qa_up_player_'.$suffix.'@example.invalid','password'=>bin2hex(random_bytes(24)),'tokens'=>100,'account_type'=>0]);
            $character=Character::create(['user_id'=>$user->id,'name'=>'QA upgrade character','gold'=>20]);
            $time=now(); $lines=[['type'=>'tokens','key'=>'','quantity'=>100],['type'=>'gold','key'=>'','quantity'=>20]];
            $campaign=DB::table('nl_campaigns')->insertGetId(['slug'=>'qa-upgrade-'.$suffix,'title'=>'QA pre-upgrade receipt','kind'=>'starter','status'=>'paused','starts_at'=>$time->copy()->subDay(),'ends_at'=>$time->copy()->addDay(),'max_claims'=>1,'claimed_count'=>1,'choices'=>json_encode([['key'=>'default','label'=>'Default','rewards'=>$lines]],JSON_THROW_ON_ERROR),'created_by'=>$admin->id,'created_at'=>$time,'updated_at'=>$time]);
            $id=(string)Str::uuid(); $key=(string)Str::uuid();
            $granted=[['type'=>'tokens','key'=>'','quantity'=>100,'before'=>0,'after'=>100],['type'=>'gold','key'=>'','quantity'=>20,'before'=>0,'after'=>20]];
            DB::table('nl_claims')->insert(['id'=>$id,'campaign_id'=>$campaign,'user_id'=>$user->id,'character_id'=>$character->id,'idempotency_key'=>$key,'request_hash'=>hash('sha256',json_encode([$character->id,$campaign,'default','starter',null],JSON_THROW_ON_ERROR)),'choice_key'=>'default','source'=>'player_portal','actor_id'=>$user->id,'reason'=>'QA pre-upgrade receipt','granted'=>json_encode($granted,JSON_THROW_ON_ERROR),'created_at'=>$time]);
            foreach ($granted as $line) { DB::table('nl_reward_ledger')->insert(['claim_id'=>$id,'catalog_id'=>null,'kind'=>$line['type'],'content_key'=>'','quantity'=>$line['quantity'],'balance_before'=>$line['before'],'balance_after'=>$line['after'],'created_at'=>$time]); }
            $oldReceipt=(array)DB::table('nl_claims')->where('id',$id)->first();
            $oldLedger=json_encode(DB::table('nl_reward_ledger')->orderBy('id')->get(),JSON_THROW_ON_ERROR);
            (require $migration)->up();
            $this->assertSame($oldReceipt,(array)DB::table('nl_claims')->where('id',$id)->first());
            $this->assertSame($oldLedger,json_encode(DB::table('nl_reward_ledger')->orderBy('id')->get(),JSON_THROW_ON_ERROR));
            $this->assertSame(100,(int)$user->fresh()->tokens); $this->assertSame(20,(int)$character->fresh()->gold);
            $this->assertSame(0,StateRevision::current($user->id));
            $retry=app(RewardService::class)->starter($user,$character->id,$campaign,'default',$key);
            $this->assertTrue($retry['replayed']); $this->assertSame($id,$retry['id']);
            $this->assertSame(0,StateRevision::current($user->id));
            app(StaffGrantService::class)->grant($admin,$character->id,[['type'=>'tokens','key'=>'','quantity'=>7]],'QA after schema upgrade',(string)Str::uuid());
            $this->assertSame(107,(int)$user->fresh()->tokens); $this->assertSame(1,StateRevision::current($user->id));
            $this->assertSame(2,DB::table('nl_claims')->count());
            try { (require $migration)->down(); $this->fail('Automatic destructive rollback must be refused'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('data-preserving',$e->getMessage()); }
            $this->assertSame(2,DB::table('nl_claims')->count());
        } finally {
            DB::purge('mysql'); config(['database.connections.mysql'=>$original]);
            if ($created) { DB::statement('DROP DATABASE `'.$database.'`'); }
        }
    }
}
