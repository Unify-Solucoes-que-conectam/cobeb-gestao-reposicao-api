<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClientesController;

Route::get('', [ClientesController::class, 'index']);
Route::get('/{id}', [ClientesController::class, 'show']);
