<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapasController;

Route::get('', [MapasController::class, 'index']);
Route::get('/{mapa}', [MapasController::class, 'show']);
