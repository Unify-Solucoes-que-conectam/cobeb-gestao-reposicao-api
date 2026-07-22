<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('', [ImportController::class, 'list']);
Route::get('{id}', [ImportController::class, 'show']);
Route::post('', [ImportController::class, 'start']);
