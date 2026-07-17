<?php

use App\Http\Controllers\ProdutosController;
use Illuminate\Support\Facades\Route;

Route::get('', [ProdutosController::class, 'index']);
Route::get('/{codigo}', [ProdutosController::class, 'show']);
