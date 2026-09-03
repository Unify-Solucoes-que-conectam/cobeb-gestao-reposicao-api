<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WhatsAppMediaController;
use App\Support\AppRouter;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
Route::get('whatsapp/media/{encodedPath}', [WhatsAppMediaController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('whatsapp.media')
;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('auth/logout', [AuthController::class, 'logout']);

    AppRouter::load(base_path('routes/private'));

    // Carrega as regras de autorização dos canais
    require __DIR__ . '/channels.php';
});
