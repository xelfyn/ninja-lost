<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use Illuminate\Support\Facades\DB;
Fixture::guard();
$statePath=getenv('NL_QA_STATE');
if(!$statePath) { throw new RuntimeException('Ephemeral state file required'); }
$mode=$argv[1]??'';
if($mode==='prepare') {
    $handle=fopen($statePath,'x'); if(!$handle) { throw new RuntimeException('Refuse existing fixture state'); } chmod($statePath,0600);
    $admin=Fixture::actor(99); $player=Fixture::actor(0,getenv('NL_QA_EMAIL'),getenv('NL_QA_PASSWORD')); $char=Fixture::character($player);
    $lines=[['type'=>'tokens','key'=>'','quantity'=>5000],['type'=>'gold','key'=>'','quantity'=>2500]]; $catalogs=[];
    foreach(['item','skill','pet'] as $kind) { $line=Fixture::content($admin,$kind,100); $catalogs[]=$line['id']; unset($line['id']); if($kind==='item') { $line['quantity']=2; } $lines[]=$line; }
    $campaign=Fixture::campaign($admin,$lines);
    $redeem=Fixture::campaign($admin,[['type'=>'tokens','key'=>'','quantity'=>200],['type'=>'gold','key'=>'','quantity'=>100]],['kind'=>'redeem','title'=>'QA Synthetic Redeem','code'=>getenv('NL_QA_CODE')]);
    $state=['users'=>[$admin->id,$player->id],'campaigns'=>[$campaign,$redeem],'catalogs'=>$catalogs,'user'=>$player->id,'character'=>$char->id,'campaign'=>$campaign];
    fwrite($handle,json_encode($state,JSON_THROW_ON_ERROR)); fclose($handle);
    echo json_encode(['campaign'=>$campaign,'character'=>$char->id],JSON_THROW_ON_ERROR);
} else {
    $state=json_decode(file_get_contents($statePath),true,32,JSON_THROW_ON_ERROR);
    if($mode==='cleanup') { Fixture::cleanup($state['users'],$state['campaigns'],$state['catalogs']); unlink($statePath); exit; }
    $redeemed=$mode==='verify-redeem';
    if(!in_array($mode,['verify-starter','verify-redeem'],true)) { throw new RuntimeException('Unknown fixture command'); }
    $tokens=(int)DB::table('users')->where('id',$state['user'])->value('tokens');
    $gold=(int)DB::table('characters')->where('id',$state['character'])->value('gold');
    if($tokens!==($redeemed?5200:5000)||$gold!==($redeemed?2600:2500)) { throw new RuntimeException('Database balance differs from browser expectation'); }
    if(DB::table('nl_claims')->where('user_id',$state['user'])->count()!==($redeemed?2:1)) { throw new RuntimeException('Wrong number of committed claims'); }
    if((int)DB::table('character_items')->where('character_id',$state['character'])->sum('quantity')!==2 || DB::table('character_skills')->where('character_id',$state['character'])->count()!==1 || DB::table('character_pets')->where('character_id',$state['character'])->count()!==1) { throw new RuntimeException('Shared inventory not updated'); }
    echo "PASS independent database receipt and inventory check\n";
}
