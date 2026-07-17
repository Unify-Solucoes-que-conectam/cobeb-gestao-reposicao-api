<?php

use App\Http\Controllers\TiposAvariaController;
use Illuminate\Support\Facades\Route;

Route::get('', [TiposAvariaController::class, 'index']);
