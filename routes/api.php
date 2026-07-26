<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Support\AppRouter;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
Route::post('auth/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('auth/logout', [AuthController::class, 'logout']);

    AppRouter::load(base_path('routes/private'));

    // Carrega as regras de autorização dos canais
    require __DIR__ . '/channels.php';
});
