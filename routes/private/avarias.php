<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AvariasController;

Route::get('', [AvariasController::class, 'index']);
Route::post('', [AvariasController::class, 'store']);
Route::put('/{id}', [AvariasController::class, 'update']);
Route::put('/{id}', [AvariasController::class, 'update']);
Route::put('/{id}/status', [AvariasController::class, 'updateStatus']);
Route::put('/{avariaId}/produtos/{produtoId}', [AvariasController::class, 'updateQuantidadeProduto']);
Route::delete('/{id}', [AvariasController::class, 'destroy']);
Route::get('/{id}/itens', [AvariasController::class, 'itens']);
