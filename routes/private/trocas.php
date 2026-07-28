<?php

use App\Http\Controllers\TrocasController;
use Illuminate\Support\Facades\Route;

Route::get('', [TrocasController::class, 'index']);
