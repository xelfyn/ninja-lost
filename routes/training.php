<?php

use App\Evaluation\GatewayBoundary;
use App\Evaluation\TrainingBoundary;
use App\Evaluation\TrainingGateway;
use Illuminate\Support\Facades\Route;

if (TrainingBoundary::enabled()) {
    Route::prefix('evaluation/v1/training')->middleware([GatewayBoundary::class, TrainingBoundary::class])->group(function () {
        Route::get('/client', [TrainingGateway::class, 'client']);
        Route::get('/identity', [TrainingGateway::class, 'identity']);
        Route::get('/characters/{character}/loadout', [TrainingGateway::class, 'loadout'])->where('character', '[1-9][0-9]{0,9}');
        Route::post('/characters/{character}/loadout', [TrainingGateway::class, 'saveLoadout'])->where('character', '[1-9][0-9]{0,9}');
        Route::get('/characters/{character}/latest', [TrainingGateway::class, 'latest'])->where('character', '[1-9][0-9]{0,9}');
        Route::post('/runs', [TrainingGateway::class, 'start']);
        Route::get('/runs/{run}', [TrainingGateway::class, 'show'])->whereUuid('run');
        Route::post('/runs/{run}/actions', [TrainingGateway::class, 'action'])->whereUuid('run');
    });
}
