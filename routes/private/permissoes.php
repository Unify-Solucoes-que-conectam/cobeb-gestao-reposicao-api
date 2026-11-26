<?php

use App\Http\Controllers\PermissoesController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:permissoes.gerenciar')->group(function () {
    Route::get('catalogo', [PermissoesController::class, 'catalogo']);

    Route::get('grupos', [PermissoesController::class, 'grupos']);
    Route::post('grupos', [PermissoesController::class, 'salvarGrupo']);
    Route::put('grupos/{id}', [PermissoesController::class, 'salvarGrupo']);
    Route::delete('grupos/{id}', [PermissoesController::class, 'excluirGrupo']);

    Route::get('usuarios', [PermissoesController::class, 'usuarios']);
    Route::put('usuarios/{id}', [PermissoesController::class, 'salvarUsuario']);
});
