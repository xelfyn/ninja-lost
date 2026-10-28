<?php

use App\Evaluation\GatewayBoundary;
use App\Evaluation\Trials\TrialBoundary;
use App\Evaluation\Trials\TrialGateway;
use Illuminate\Support\Facades\Route;

if (TrialBoundary::enabled()) {
    Route::prefix('evaluation/v1/trials')->middleware([GatewayBoundary::class, TrialBoundary::class])->group(function () {
        Route::get('/client', [TrialGateway::class, 'client']);
        Route::get('/definitions', [TrialGateway::class, 'definitions']);
        Route::get('/characters/{character}/latest', [TrialGateway::class, 'latest'])->where('character', '[1-9][0-9]{0,9}');
        Route::post('/runs', [TrialGateway::class, 'start']);
        Route::get('/runs/{battle}', [TrialGateway::class, 'show'])->whereUuid('battle');
        Route::post('/runs/{battle}/actions', [TrialGateway::class, 'action'])->whereUuid('battle');
        Route::post('/runs/{battle}/finish', [TrialGateway::class, 'finish'])->whereUuid('battle');
    });
}
