<?php

use App\Evaluation\GatewayAccess;
use App\Evaluation\GatewayBoundary;
use App\Evaluation\PlayerGateway;
use Illuminate\Support\Facades\Route;

// Kept inside Laravel's web group: encrypted cookies, server sessions and CSRF all remain.
if (GatewayAccess::enabled()) {
    Route::prefix('evaluation/v1')->middleware(GatewayBoundary::class)->group(function () {
        Route::get('/bootstrap', [PlayerGateway::class, 'bootstrap']);
        Route::post('/login', [PlayerGateway::class, 'login']);
        Route::post('/logout', [PlayerGateway::class, 'logout']);
        Route::get('/characters', [PlayerGateway::class, 'characters']);
        Route::get('/characters/{character}/state', [PlayerGateway::class, 'state'])->where('character', '[1-9][0-9]{0,9}');
        Route::get('/characters/{character}/inventory', [PlayerGateway::class, 'inventory'])->where('character', '[1-9][0-9]{0,9}');
        Route::post('/characters/{character}/equipment', [PlayerGateway::class, 'equipment'])->where('character', '[1-9][0-9]{0,9}');
        Route::get('/offers', [PlayerGateway::class, 'offers']);
        Route::get('/campaigns', [PlayerGateway::class, 'campaigns']);
        Route::post('/starter-claims', [PlayerGateway::class, 'starter']);
        Route::post('/redeem-claims', [PlayerGateway::class, 'redeem']);
        Route::post('/purchases', [PlayerGateway::class, 'purchase']);
    });
}

require __DIR__.'/training.php';
require __DIR__.'/mirror-trials.php';
