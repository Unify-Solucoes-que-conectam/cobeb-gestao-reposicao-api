<?php

use App\Http\Controllers\TrocasController;
use Illuminate\Support\Facades\Route;

Route::get('exportar', [TrocasController::class, 'export']);
Route::get('', [TrocasController::class, 'index']);
