<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapasController;

Route::get('', [MapasController::class, 'index']);
Route::get('/{mapa}', [MapasController::class, 'show']);

Route::get('/{id}/clientes', [MapasController::class, 'clientes']);
Route::get('/{id}/avarias', [MapasController::class, 'avarias']);
Route::put('/{id}/designar-motorista/{motorista_id}', [MapasController::class, 'designarMotorista']);
