<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
Fixture::guard();
function check(bool $value,string $message): void { if(!$value) { throw new RuntimeException($message); } }
function race(array $requests): array {
    $barrier=sys_get_temp_dir().'/nl-race-'.bin2hex(random_bytes(12)); $jobs=[];
    try {
        foreach($requests as $request) {
            $pipes=[];
            $proc=proc_open(array_merge([PHP_BINARY,__DIR__.'/race-worker.php'],array_map('strval',$request),[$barrier]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc)) { throw new RuntimeException('Worker failed to start'); }
            fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
            $jobs[]=['proc'=>$proc,'out'=>$pipes[1],'err'=>$pipes[2],'text'=>'','errors'=>'','done'=>false];
        }
        touch($barrier); $deadline=microtime(true)+35;
        do {
            $pending=false;
            foreach($jobs as &$job) {
                $job['text'].=stream_get_contents($job['out']); $job['errors'].=stream_get_contents($job['err']);
                if(!$job['done']) { $state=proc_get_status($job['proc']); $job['done']=!$state['running']; if($job['done']) { $job['exit']=$state['exitcode']; } }
                $pending=$pending || !$job['done'];
            } unset($job);
            if(microtime(true)>$deadline) { throw new RuntimeException('Race workers timed out'); }
            if($pending) { usleep(20000); }
        } while($pending);
        $results=[];
        foreach($jobs as &$job) {
            $job['text'].=stream_get_contents($job['out']); $job['errors'].=stream_get_contents($job['err']);
            check($job['exit']===0,'Worker exit: '.substr($job['errors'],0,1000));
            $results[]=json_decode($job['text'],true,16,JSON_THROW_ON_ERROR);
        } unset($job);
        return $results;
    } finally {
        foreach($jobs as $job) { if(is_resource($job['proc'])) { if(proc_get_status($job['proc'])['running']) { proc_terminate($job['proc'],9); } fclose($job['out']); fclose($job['err']); proc_close($job['proc']); } }
        if(is_file($barrier)) { unlink($barrier); }
    }
}
$users=[]; $campaigns=[]; $catalogs=[];
try {
    $admin=Fixture::actor(99); $users[]=$admin->id;
    $player=Fixture::actor(); $users[]=$player->id; $char=Fixture::character($player); $char2=Fixture::character($player);
    $campaign=Fixture::campaign($admin); $campaigns[]=$campaign; $key=(string)Str::uuid();
    $results=race(array_fill(0,8,[$player->id,$char->id,$campaign,$key]));
    check(count(array_filter($results,fn($r)=>$r['ok']&&!$r['replayed']))===1,'Idempotency double grant');
    check(count(array_filter($results,fn($r)=>$r['ok']))===8,'Idempotent requests should all return successful receipt');
    check((int)$player->fresh()->tokens===5000,'Unexpected token balance after replay race');
    echo "PASS race-idempotent: 8 concurrent workers, one grant and seven original receipts\n";
    $campaign=Fixture::campaign($admin); $campaigns[]=$campaign; $requests=[];
    for($i=0;$i<8;$i++) { $requests[]=[$player->id,$i%2?$char->id:$char2->id,$campaign,(string)Str::uuid()]; }
    $results=race($requests); check(count(array_filter($results,fn($r)=>$r['ok']))===1,'Cross-character duplicate claim');
    echo "PASS race-account-scope: 8 workers across two characters, exactly one account claim\n";
    $actors=[];
    for($i=0;$i<8;$i++) { $u=Fixture::actor(); $users[]=$u->id; $c=Fixture::character($u); $actors[]=[$u->id,$c->id]; }
    $campaign=Fixture::campaign($admin,[],['max_claims'=>3]); $campaigns[]=$campaign;
    $results=race(array_map(fn($a)=>[$a[0],$a[1],$campaign,(string)Str::uuid()],$actors));
    check(count(array_filter($results,fn($r)=>$r['ok']))===3,'Campaign quota race failed');
    check((int)DB::table('nl_campaigns')->where('id',$campaign)->value('claimed_count')===3,'Campaign counter mismatch');
    echo "PASS race-campaign-quota: 8 accounts competing for three claims, exactly three committed\n";
    $skill=Fixture::content($admin,'skill',3); $catalogs[]=$skill['id']; $catalogId=$skill['id']; unset($skill['id']);
    $a=Fixture::campaign($admin,[$skill]); $b=Fixture::campaign($admin,[$skill]); $campaigns[]=$a; $campaigns[]=$b;
    $requests=[]; foreach($actors as $i=>$actor) { $requests[]=[$actor[0],$actor[1],$i%2?$a:$b,(string)Str::uuid()]; }
    $results=race($requests); check(count(array_filter($results,fn($r)=>$r['ok']))===3,'Global supply race failed');
    check((int)DB::table('nl_catalog')->where('id',$catalogId)->value('issued')===3,'Global supply counter mismatch');
    check(DB::table('character_skills')->where('skill_id',$skill['key'])->count()===3,'Physical inventory differs from supply ledger');
    check(DB::table('nl_reward_ledger')->where('catalog_id',$catalogId)->sum('quantity')==3,'Reward ledger mismatch');
    echo "PASS race-global-supply: two campaigns share three units; ledger and actual inventory reconcile\n";
} finally { Fixture::cleanup($users,$campaigns,$catalogs); }
