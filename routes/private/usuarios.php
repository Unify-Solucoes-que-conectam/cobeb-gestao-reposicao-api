<?php

use App\Http\Controllers\UsuariosController;
use Illuminate\Support\Facades\Route;

Route::patch('alterar-senha/{id}', [UsuariosController::class, 'alterarSenha']); // Rota para alterar a senha do usuário

Route::get('/menus-favoritos', [UsuariosController::class, 'getMenusFavoritos']);
Route::post('/favoritar-menu/{menu}', [UsuariosController::class, 'favoritarMenu']);

Route::middleware('can:manage-users')->group(function () {
    Route::post('', [UsuariosController::class, 'store']);
    Route::get('', [UsuariosController::class, 'index']);
    Route::get('/{cpf}', [UsuariosController::class, 'show']);
    Route::patch('/{id}', [UsuariosController::class, 'update']);
    Route::delete('/{id}', [UsuariosController::class, 'destroy']);
});
