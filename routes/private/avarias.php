<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AvariasController;

Route::get('', [AvariasController::class, 'index']);
Route::post('', [AvariasController::class, 'store']);
