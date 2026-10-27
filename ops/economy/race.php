<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../rewards/Fixture.php';
use NinjaLost\QA\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Economy\StateRevision;
Fixture::guard();
function verify(bool $condition,string $message):void { if (!$condition) { throw new RuntimeException($message); } }
function concurrent(array $requests):array {
    $directory=sys_get_temp_dir().'/nl-g8-race-'.bin2hex(random_bytes(12)); mkdir($directory,0700); $jobs=[];
    try {
        foreach ($requests as $i=>$request) {
            $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/race-worker.php',base64_encode(json_encode($request,JSON_THROW_ON_ERROR)),$directory,(string)$i], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            verify(is_resource($process),'Unable to start worker'); fclose($pipes[0]);
            stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
            $jobs[]=['process'=>$process,'out'=>$pipes[1],'err'=>$pipes[2],'text'=>'','error'=>'','done'=>false,'exit'=>null];
        }
        $readyDeadline=microtime(true)+30;
        while (count(glob($directory.'/ready-*'))!==count($requests)) {
            if (microtime(true)>$readyDeadline) { throw new RuntimeException('Workers did not reach start barrier'); }
            usleep(20000); clearstatcache();
        }
        touch($directory.'/start'); $deadline=microtime(true)+40;
        do {
            $pending=false;
            foreach ($jobs as &$job) {
                $job['text'].=stream_get_contents($job['out']); $job['error'].=stream_get_contents($job['err']);
                if (!$job['done']) { $state=proc_get_status($job['process']); if (!$state['running']) { $job['done']=true; $job['exit']=$state['exitcode']; } }
                $pending=$pending || !$job['done'];
            } unset($job);
            if (microtime(true)>$deadline) { throw new RuntimeException('Workers exceeded transaction deadline'); }
            if ($pending) { usleep(20000); }
        } while ($pending);
        $results=[];
        foreach ($jobs as &$job) {
            $job['text'].=stream_get_contents($job['out']); $job['error'].=stream_get_contents($job['err']);
            verify($job['exit']===0,'Worker failed: '.substr($job['error'],0,1500));
            $results[]=json_decode($job['text'],true,32,JSON_THROW_ON_ERROR);
        } unset($job);
        return $results;
    } finally {
        foreach ($jobs as $job) {
            if (proc_get_status($job['process'])['running']) { proc_terminate($job['process'],9); }
            fclose($job['out']); fclose($job['err']); proc_close($job['process']);
        }
        foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
    }
}
$users=[]; $campaigns=[]; $catalogs=[];
try {
    $admin=Fixture::actor(99); $users[]=$admin->id;
    $player=Fixture::actor(); $users[]=$player->id; $char=Fixture::character($player);
    $lines=[['type'=>'tokens','key'=>'','quantity'=>10]];
    $request=['op'=>'staff','actor'=>$admin->id,'character'=>$char->id,'lines'=>$lines,'key'=>(string)Str::uuid()];
    $results=concurrent(array_fill(0,8,$request));
    verify(count(array_filter($results,fn($r)=>$r['ok']))===8,'Staff retries must all receive a receipt');
    verify(count(array_filter($results,fn($r)=>!$r['result']['replayed']))===1,'Duplicate staff issuance');
    verify((int)$player->fresh()->tokens===10 && StateRevision::current($player->id)===1,'Staff retry balance/revision mismatch');
    echo "PASS staff-idempotency: eight ready workers, one issuance and seven original receipts\n";

    $player=Fixture::actor(); $users[]=$player->id; $char=Fixture::character($player);
    $lines=[['type'=>'tokens','key'=>'','quantity'=>10],['type'=>'gold','key'=>'','quantity'=>5]]; $requests=[];
    for ($i=0;$i<8;$i++) {
        if ($i%2===0) { $id=Fixture::campaign($admin,$lines); $campaigns[]=$id; $requests[]=['op'=>'portal','actor'=>$player->id,'character'=>$char->id,'campaign'=>$id,'key'=>(string)Str::uuid()]; }
        else { $requests[]=['op'=>'staff','actor'=>$admin->id,'character'=>$char->id,'lines'=>$lines,'key'=>(string)Str::uuid()]; }
    }
    $results=concurrent($requests); verify(count(array_filter($results,fn($r)=>$r['ok']))===8,'Cross-adapter valid requests must commit');
    verify((int)$player->fresh()->tokens===80 && (int)$char->fresh()->gold===40 && StateRevision::current($player->id)===8,'Cross-adapter lost update');
    $claimIds=DB::table('nl_claims')->where('user_id',$player->id)->pluck('id');
    $before=DB::table('nl_reward_ledger')->whereIn('claim_id',$claimIds)->where('kind','tokens')->orderBy('balance_before')->pluck('balance_before')->map(fn($n)=>(int)$n)->all();
    verify($before===range(0,70,10),'Ledger does not form an uninterrupted balance chain');
    echo "PASS portal-staff-balance: eight ready workers, exact summed balances, revision eight and continuous ledger chain\n";

    $content=Fixture::content($admin,'skill',3); $catalogs[]=$content['id']; $catalogId=$content['id']; unset($content['id']);
    $campaign=Fixture::campaign($admin,[$content]); $campaigns[]=$campaign; $requests=[];
    for ($i=0;$i<8;$i++) {
        $p=Fixture::actor(); $users[]=$p->id; $c=Fixture::character($p);
        $requests[]=$i%2===0 ? ['op'=>'portal','actor'=>$p->id,'character'=>$c->id,'campaign'=>$campaign,'key'=>(string)Str::uuid()]
            : ['op'=>'staff','actor'=>$admin->id,'character'=>$c->id,'lines'=>[$content],'key'=>(string)Str::uuid()];
    }
    $results=concurrent($requests); verify(count(array_filter($results,fn($r)=>$r['ok']))===3,'Shared stock exceeded or undershot');
    verify((int)DB::table('nl_catalog')->where('id',$catalogId)->value('issued')===3,'Supply counter mismatch');
    verify(DB::table('character_skills')->where('skill_id',$content['key'])->count()===3,'Physical inventory mismatch');
    verify((int)DB::table('nl_reward_ledger')->where('catalog_id',$catalogId)->sum('quantity')===3,'Ledger supply mismatch');
    echo "PASS portal-staff-limited-stock: eight accounts, two adapters, exactly three skill units across counter/ledger/inventory\n";

    $p=Fixture::actor(); $users[]=$p->id; $c=Fixture::character($p); $requests=[['op'=>'save','actor'=>$p->id,'character'=>$c->id,'revision'=>0]];
    for ($i=0;$i<7;$i++) { $requests[]=['op'=>'staff','actor'=>$admin->id,'character'=>$c->id,'lines'=>[['type'=>'tokens','key'=>'','quantity'=>10]],'key'=>(string)Str::uuid()]; }
    $results=concurrent($requests);
    verify(count(array_filter(array_slice($results,1),fn($r)=>$r['ok']))===7,'Valid grants lost during equipment save');
    verify($results[0]['ok'] || ($results[0]['rejection']??null)==='conflict','Unexpected stale-save result');
    verify((int)$p->fresh()->tokens===70,'Stale save overwrote grant');
    verify(StateRevision::current($p->id)===7+($results[0]['ok']?1:0),'Save/grant revision mismatch');
    verify(DB::table('nl_claims')->where('user_id',$p->id)->count()===7,'Unexpected reward receipt count');
    echo "PASS equipment-grant-race: seven grants retained; old save serialized first or rejected with conflict\n";
} finally {
    // Remove only this process's recipients and receipts in the isolated disposable database.
    $claims=DB::table('nl_claims')->whereIn('user_id',$users)->pluck('id');
    DB::table('nl_reward_ledger')->whereIn('claim_id',$claims)->delete();
    DB::table('nl_claims')->whereIn('id',$claims)->delete();
    Fixture::cleanup($users,$campaigns,$catalogs);
}
