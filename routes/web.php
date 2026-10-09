<?php

use App\Http\Controllers\BunnyTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BunnyTestController::class, 'index']);

Route::prefix('api')->group(function () {
    Route::get('config', [BunnyTestController::class, 'config']);

    Route::get('videos', [BunnyTestController::class, 'listVideos']);
    Route::post('videos', [BunnyTestController::class, 'storeVideo']);
    Route::get('videos/{videoId}', [BunnyTestController::class, 'showVideo']);
    Route::get('videos/{videoId}/play', [BunnyTestController::class, 'play']);
    Route::delete('videos/{videoId}', [BunnyTestController::class, 'destroyVideo']);

    Route::get('collections', [BunnyTestController::class, 'listCollections']);
    Route::post('collections', [BunnyTestController::class, 'storeCollection']);

    Route::get('webhook-events', [BunnyTestController::class, 'webhookEvents']);
});

// O Bunny não envia token CSRF: a segurança deste endpoint é a assinatura HMAC.
Route::post('webhooks/bunny', [BunnyTestController::class, 'webhook']);
