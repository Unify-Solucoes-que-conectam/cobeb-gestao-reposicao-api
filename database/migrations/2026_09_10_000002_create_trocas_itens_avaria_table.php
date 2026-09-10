<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trocas_itens_avaria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('troca_id')->constrained('trocas')->cascadeOnDelete();
            $table->foreignUuid('item_avaria_id')->constrained('itens_avaria')->cascadeOnDelete();
            $table->unsignedInteger('quantidade');
            $table->timestamps();
            $table->unique(['troca_id', 'item_avaria_id'], 'troca_item_avaria_unique');
        });

        // Associa movimentos antigos às avarias aprovadas mais antigas do item.
        DB::table('trocas')->distinct()->orderBy('produto_nota_fiscal_id')->pluck('produto_nota_fiscal_id')
            ->each(function (string $produtoNotaFiscalId) {
                $itens = DB::table('itens_avaria')
                    ->join('avarias', 'avarias.id', '=', 'itens_avaria.avaria_id')
                    ->where('itens_avaria.produto_nota_fiscal_id', $produtoNotaFiscalId)
                    ->whereIn('avarias.status', ['aprovada', 'trocada'])
                    ->orderByRaw('COALESCE(avarias.data_aprovacao, avarias.data_emissao)')
                    ->orderBy('avarias.created_at')
                    ->orderBy('itens_avaria.id')
                    ->get(['itens_avaria.id', 'itens_avaria.quantidade_avariada']);
                $trocas = DB::table('trocas')
                    ->where('produto_nota_fiscal_id', $produtoNotaFiscalId)
                    ->orderBy('data_operacao')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['id', 'quantidade']);
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
            });

        DB::table('avarias')->where('status', 'aprovada')->orderBy('id')->pluck('id')
            ->each(function (string $avariaId) {
                $itens = DB::table('itens_avaria')->where('avaria_id', $avariaId)->get(['id', 'quantidade_avariada']);
                $atendida = $itens->isNotEmpty() && $itens->every(function ($item) {
                    return (int) DB::table('trocas_itens_avaria')
                        ->where('item_avaria_id', $item->id)
                        ->sum('quantidade') >= (int) $item->quantidade_avariada;
                });

                if ($atendida) {
                    DB::table('avarias')->where('id', $avariaId)->update([
                        'status' => 'trocada',
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('trocas_itens_avaria');
    }
};
