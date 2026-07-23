<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClientesController;

Route::get('', [ClientesController::class, 'index']);
Route::get('/{id}', [ClientesController::class, 'show']);
Route::get('/{id}/notas-fiscais', [ClientesController::class, 'notasFiscais']);
Route::get('/{id}/notas-fiscais/{search}', [ClientesController::class, 'notasFiscais']);
