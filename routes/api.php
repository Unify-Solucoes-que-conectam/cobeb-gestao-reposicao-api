<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Support\AppRouter;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum'])->group(function () {
    // protegidas
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('auth/logout', [AuthController::class, 'logout']);

    AppRouter::load(base_path('routes/private'));
});
