<?php

use App\Http\Controllers\WhatsAppConfigurationController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-whatsapp')->group(function () {
    Route::get('configurations', [WhatsAppConfigurationController::class, 'index']);
    Route::get('configurations/{filial}', [WhatsAppConfigurationController::class, 'show']);
    Route::put('configurations/{filial}/official', [WhatsAppConfigurationController::class, 'official']);
    Route::put('configurations/{filial}/baileys', [WhatsAppConfigurationController::class, 'baileys']);
    Route::get('configurations/{filial}/connection', [WhatsAppConfigurationController::class, 'connection']);
    Route::post('configurations/{filial}/qrcode', [WhatsAppConfigurationController::class, 'qrcode']);
    Route::get('configurations/{filial}/official/templates', [WhatsAppConfigurationController::class, 'officialTemplates']);
    Route::put('configurations/{filial}/templates', [WhatsAppConfigurationController::class, 'templates']);
    Route::post('configurations/{filial}/test', [WhatsAppConfigurationController::class, 'test']);
    Route::post('configurations/{filial}/reconnect', [WhatsAppConfigurationController::class, 'reconnect']);
    Route::delete('configurations/{filial}', [WhatsAppConfigurationController::class, 'destroy']);
});
