<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrocaAvariaAllocationService
{
    public function rebuild(string $produtoNotaFiscalId): void
    {
        $trocas = DB::table('trocas')
            ->where('produto_nota_fiscal_id', $produtoNotaFiscalId)
            ->orderBy('data_operacao')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'quantidade']);
        $trocaIds = $trocas->pluck('id');

        if ($trocaIds->isNotEmpty()) {
            DB::table('trocas_itens_avaria')->whereIn('troca_id', $trocaIds)->delete();
        }

        $itens = DB::table('itens_avaria')
            ->join('avarias', 'avarias.id', '=', 'itens_avaria.avaria_id')
            ->where('itens_avaria.produto_nota_fiscal_id', $produtoNotaFiscalId)
            ->whereIn('avarias.status', ['aprovada', 'trocada'])
            ->orderByRaw('COALESCE(avarias.data_aprovacao, avarias.data_emissao)')
            ->orderBy('avarias.created_at')
            ->orderBy('itens_avaria.id')
            ->lockForUpdate()
            ->get(['itens_avaria.id', 'itens_avaria.avaria_id', 'itens_avaria.quantidade_avariada']);
        $consumido = [];

        foreach ($trocas as $troca) {
            $restante = (int) $troca->quantidade;

            foreach ($itens as $item) {
                if ($restante <= 0) {
                    break;
                }

                $disponivel = max(0, (int) $item->quantidade_avariada - ($consumido[$item->id] ?? 0));
                $quantidade = min($restante, $disponivel);
                if ($quantidade <= 0) {
                    continue;
                }

                DB::table('trocas_itens_avaria')->insert([
                    'id' => (string) Str::uuid(),
                    'troca_id' => $troca->id,
                    'item_avaria_id' => $item->id,
                    'quantidade' => $quantidade,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $consumido[$item->id] = ($consumido[$item->id] ?? 0) + $quantidade;
                $restante -= $quantidade;
            }
        }

        foreach ($itens->pluck('avaria_id')->unique() as $avariaId) {
            $itensDaAvaria = DB::table('itens_avaria')->where('avaria_id', $avariaId)->get(['id', 'quantidade_avariada']);
            $atendida = $itensDaAvaria->isNotEmpty() && $itensDaAvaria->every(function ($item) {
                $quantidadeTrocada = (int) DB::table('trocas_itens_avaria')
                    ->where('item_avaria_id', $item->id)
                    ->sum('quantidade');

                return $quantidadeTrocada >= (int) $item->quantidade_avariada;
            });

            $novoStatus = $atendida ? 'trocada' : 'aprovada';
            DB::table('avarias')
                ->where('id', $avariaId)
                ->where('status', '!=', $novoStatus)
                ->update(['status' => $novoStatus, 'updated_at' => now()]);
        }
    }
}
