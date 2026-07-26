<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClustersController;

Route::get('', [ClustersController::class, 'index']);
Route::get('/{id}', [ClustersController::class, 'show']);
