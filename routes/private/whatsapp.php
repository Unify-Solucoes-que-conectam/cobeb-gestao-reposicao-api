<?php

use App\Http\Controllers\WhatsAppConfigurationController;
use App\Http\Middleware\EnsureWhatsAppTenantAccess;
use Illuminate\Support\Facades\Route;

// fora do tenant "Geral", só a filial conectada pode ser configurada (e nunca a instância global)
Route::middleware(['can:manage-whatsapp', EnsureWhatsAppTenantAccess::class])->group(function () {
    Route::get('configurations', [WhatsAppConfigurationController::class, 'index']);
    Route::get('configurations/{filial}', [WhatsAppConfigurationController::class, 'show']);
    Route::put('configurations/{filial}/official', [WhatsAppConfigurationController::class, 'official']);
    Route::put('configurations/{filial}/baileys', [WhatsAppConfigurationController::class, 'baileys']);
    Route::get('configurations/{filial}/connection', [WhatsAppConfigurationController::class, 'connection']);
    Route::post('configurations/{filial}/qrcode', [WhatsAppConfigurationController::class, 'qrcode']);
    Route::get('configurations/{filial}/official/templates', [WhatsAppConfigurationController::class, 'officialTemplates']);
    Route::put('configurations/{filial}/templates', [WhatsAppConfigurationController::class, 'templates']);
    Route::post('configurations/{filial}/test', [WhatsAppConfigurationController::class, 'test'])->name('whatsapp.test');
    Route::post('configurations/{filial}/reconnect', [WhatsAppConfigurationController::class, 'reconnect']);
    Route::put('configurations/{filial}/global', [WhatsAppConfigurationController::class, 'setGlobal'])->name('whatsapp.global');
    Route::delete('configurations/{filial}', [WhatsAppConfigurationController::class, 'destroy']);
});
