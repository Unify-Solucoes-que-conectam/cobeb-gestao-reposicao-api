<?php

namespace App\Console\Commands;

use App\Models\Mapa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Encerra todas as rotas (mapas) designadas antes de hoje e os mapas com entrega vencida.
 * Roda às 00h: um mapa designado ontem deixa de aparecer para o motorista.
 * O motorista_id é mantido para o histórico (avarias continuam mostrando a rota).
 */
class EncerrarMapas extends Command
{
    protected $signature = 'mapas:encerrar';

    protected $description = 'Encerra as rotas (mapas) designadas antes de hoje';

    public function handle(): int
    {
        $total = Mapa::query()
            ->whereNull('encerrado_em')
            ->where(fn ($q) => $q
                // rotas designadas antes de hoje
                ->where(fn ($q) => $q->whereNotNull('motorista_id')
                    ->where(fn ($q) => $q->whereNull('designado_em')->orWhere('designado_em', '<', today())))
                // mapas cuja entrega já passou, mesmo sem motorista
                ->orWhereDate('data_entrega', '<', today()))
            ->update(['encerrado_em' => now()]);

        Log::info('[mapas:encerrar] Rotas encerradas.', ['total' => $total]);
        $this->info("{$total} rota(s) encerrada(s).");

        return self::SUCCESS;
    }
}
