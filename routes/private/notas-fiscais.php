<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotasFiscaisController;

Route::get('', [NotasFiscaisController::class, 'index']);
Route::get('/{id}', [NotasFiscaisController::class, 'show']);
