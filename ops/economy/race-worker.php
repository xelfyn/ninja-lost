<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../rewards/Fixture.php';
use App\Models\User;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\StaffGrantService;
use NinjaLost\QA\Fixture;
Fixture::guard();
try {
    $input=json_decode(base64_decode($argv[1],true),true,32,JSON_THROW_ON_ERROR);
    $directory=$argv[2]; $number=(int)$argv[3];
    if (!is_dir($directory) || !str_starts_with(basename($directory),'nl-g8-race-')) { throw new RuntimeException('Invalid barrier directory'); }
    $actor=User::findOrFail($input['actor']);
    touch($directory.'/ready-'.$number);
    $deadline=microtime(true)+30;
    while (!is_file($directory.'/start')) {
        clearstatcache(); if (microtime(true)>$deadline) { throw new RuntimeException('Barrier deadline'); } usleep(10000);
    }
    $result=match ($input['op']) {
        'staff'=>app(StaffGrantService::class)->grant($actor,$input['character'],$input['lines'],'QA concurrent compensation',$input['key']),
        'portal'=>app(RewardService::class)->starter($actor,$input['character'],$input['campaign'],'default',$input['key']),
        'save'=>app(ClientStateService::class)->saveEquipment($actor,$input['character'],['revision'=>$input['revision'],'equipment'=>['weapon'=>'']]),
        default=>throw new RuntimeException('Invalid operation'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['ok'=>false,'rejection'=>'validation'],JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    if ($e->getStatusCode()!==409) { fwrite(STDERR,'Unexpected HTTP status '.$e->getStatusCode()); exit(1); }
    echo json_encode(['ok'=>false,'rejection'=>'conflict'],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR,get_class($e).': '.$e->getMessage()); exit(1);
}
