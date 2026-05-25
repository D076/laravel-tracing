<?php

use D076\Tracing\Http\Controllers\TracingApiController;
use D076\Tracing\Http\Controllers\TracingUiController;
use Illuminate\Support\Facades\Route;

// Static assets — no auth required, files contain no sensitive data
Route::get('assets/{file}', [TracingUiController::class, 'asset'])
    ->name('asset')
    ->where('file', '.+');

Route::middleware('tracing.auth')->group(function (): void {
    // Throttle применяется только к JSON-API, чтобы не мешать загрузке SPA-оболочки.
    Route::middleware('throttle:tracing-api')->group(function (): void {
        Route::get('api/requests', [TracingApiController::class, 'index'])->name('api.requests');
        Route::get('api/requests/{id}', [TracingApiController::class, 'show'])->name('api.request');

        Route::get('api/outgoing', [TracingApiController::class, 'outgoingIndex'])->name('api.outgoing');
        Route::get('api/outgoing/{id}', [TracingApiController::class, 'outgoingShow'])->name('api.outgoing.show');
    });

    // SPA catch-all — must be last, НЕ троттлится
    Route::get('{any?}', [TracingUiController::class, 'index'])
        ->name('index')
        ->where('any', '.*');
});
