<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AvariasController;

Route::get('', [AvariasController::class, 'index']);
Route::get('/{id}/itens', [AvariasController::class, 'itens']);

// app do motorista: registro e remoção de avarias pendentes
Route::middleware('can:avarias.registrar')->group(function () {
    Route::post('', [AvariasController::class, 'store']);
    Route::put('/{id}', [AvariasController::class, 'update']);
    Route::delete('/{id}', [AvariasController::class, 'destroy']);
});

// a ação exigida depende do status (enviar para aprovação x aprovar/reprovar): verificada no controller
Route::put('/{id}/status', [AvariasController::class, 'updateStatus']);

Route::post('/{id}/whatsapp/retry', [AvariasController::class, 'retryWhatsApp'])->middleware('can:avarias.reenviar_whatsapp');
Route::put('/{avariaId}/produtos/{produtoId}', [AvariasController::class, 'updateQuantidadeProduto'])->middleware('can:avarias.editar_quantidade');
