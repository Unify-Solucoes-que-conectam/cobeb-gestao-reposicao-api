<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MotoristasController;

Route::get('', [MotoristasController::class, 'index']);
Route::get('/{id}', [MotoristasController::class, 'show']);

Route::middleware('can:motoristas.gerenciar')->group(function () {
    Route::post('', [MotoristasController::class, 'store']);
    // o front de motoristas envia PATCH; PUT mantido por compatibilidade
    Route::match(['put', 'patch'], '/{id}', [MotoristasController::class, 'update']);
    Route::delete('/{id}', [MotoristasController::class, 'destroy']);
});
