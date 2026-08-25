<?php

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/modelo/{modelo}', [ExportController::class, 'exportarModelo']);
