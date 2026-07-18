<?php

use App\Http\Controllers\NotificarClienteController;
use Illuminate\Support\Facades\Route;

Route::post('', [NotificarClienteController::class, 'notify']);
