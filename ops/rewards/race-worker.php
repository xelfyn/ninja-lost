<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
NinjaLost\QA\Fixture::guard();
[$script,$user,$character,$campaign,$key,$barrier]=$argv;
if (!str_starts_with($barrier,sys_get_temp_dir().'/nl-race-')) { throw new RuntimeException('Invalid test barrier'); }
$until=microtime(true)+20;
while(!is_file($barrier)) { if(microtime(true)>$until) { throw new RuntimeException('Barrier timeout'); } usleep(10000); }
try {
    $result=app(App\Services\Rewards\RewardService::class)->starter(App\Models\User::findOrFail((int)$user),(int)$character,(int)$campaign,'default',$key);
    echo json_encode(['ok'=>true,'replayed'=>$result['replayed']],JSON_THROW_ON_ERROR);
} catch(Illuminate\Validation\ValidationException $e) {
    echo json_encode(['ok'=>false,'rejected'=>true],JSON_THROW_ON_ERROR);
}
