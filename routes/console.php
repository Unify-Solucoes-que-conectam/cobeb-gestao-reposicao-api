<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('whatsapp:sync-connections')->everyMinute()->withoutOverlapping();

// todas as rotas (mapas) são encerradas à meia-noite (horário de Brasília)
Schedule::command('mapas:encerrar')->dailyAt('00:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
