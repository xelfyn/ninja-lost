<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../rewards/Fixture.php';
use NinjaLost\QA\Fixture;
use Illuminate\Support\Facades\DB;
use App\Support\Economy\StateRevision;
Fixture::guard();
$path=getenv('NL_QA_STATE');
if (!$path || !str_starts_with(basename(dirname($path)),'nl-economy-browser.')) { throw new RuntimeException('Ephemeral fixture path required'); }
if (($argv[1]??'')==='prepare') {
    $admin=Fixture::actor(99,getenv('NL_ADMIN_EMAIL'),getenv('NL_ADMIN_PASSWORD'));
    $player=Fixture::actor(0,getenv('NL_PLAYER_EMAIL'),getenv('NL_PLAYER_PASSWORD'));
    $character=Fixture::character($player);
    $state=['admin'=>$admin->id,'player'=>$player->id,'character'=>$character->id];
    file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR)); chmod($path,0600);
    echo json_encode($state,JSON_THROW_ON_ERROR); exit;
}
$state=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
if (($argv[1]??'')==='verify') {
    $receipts=DB::table('nl_claims')->where('user_id',$state['player'])->get();
    if ((int)DB::table('users')->where('id',$state['player'])->value('tokens')!==37 || $receipts->count()!==1 || $receipts[0]->source!=='staff_grant' || (int)$receipts[0]->actor_id!==$state['admin'] || StateRevision::current($state['player'])!==1) { throw new RuntimeException('Staff browser balance/receipt/revision mismatch'); }
    $entries=DB::table('nl_reward_ledger')->where('claim_id',$receipts[0]->id)->get();
    if ($entries->count()!==1 || (int)$entries[0]->quantity!==37 || (int)$entries[0]->balance_before!==0 || (int)$entries[0]->balance_after!==37) { throw new RuntimeException('Staff browser ledger mismatch'); }
    echo json_encode(['receipt'=>$receipts[0]->id],JSON_THROW_ON_ERROR); exit;
}
if (($argv[1]??'')==='cleanup') {
    $ids=DB::table('nl_claims')->where('user_id',$state['player'])->pluck('id');
    DB::table('nl_reward_ledger')->whereIn('claim_id',$ids)->delete(); DB::table('nl_claims')->whereIn('id',$ids)->delete();
    Fixture::cleanup([$state['admin'],$state['player']],[],[]); unlink($path); exit;
}
throw new RuntimeException('Unknown fixture action');
