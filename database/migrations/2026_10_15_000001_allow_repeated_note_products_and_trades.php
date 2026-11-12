<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No MySQL o DDL não é transacional: se esta migration falhar no meio, o que já
        // foi aplicado permanece. Por isso cada passo só roda se ainda for necessário,
        // permitindo reexecutar a migration sem erro de "Duplicate key name".

        // produtos_nota_fiscal: o único composto também servia de índice da FK de
        // nota_fiscal_id; crie a cobertura simples antes de removê-lo.
        if (! Schema::hasIndex('produtos_nota_fiscal', 'produtos_nota_fiscal_nota_fiscal_id_index')) {
            Schema::table('produtos_nota_fiscal', function (Blueprint $table) {
                $table->index('nota_fiscal_id', 'produtos_nota_fiscal_nota_fiscal_id_index');
            });
        }
        if (Schema::hasIndex('produtos_nota_fiscal', 'produtos_nota_fiscal_nota_fiscal_id_produto_id_unique')) {
            Schema::table('produtos_nota_fiscal', function (Blueprint $table) {
                $table->dropUnique(['nota_fiscal_id', 'produto_id']);
            });
        }

        // trocas: o único também servia de índice da FK de produto_nota_fiscal_id.
        // Sem esta cobertura o MySQL recusa o dropUnique (erro 1553).
        if (! Schema::hasIndex('trocas', 'trocas_produto_nota_fiscal_id_index')) {
            Schema::table('trocas', function (Blueprint $table) {
                $table->index('produto_nota_fiscal_id', 'trocas_produto_nota_fiscal_id_index');
            });
        }
        if (Schema::hasIndex('trocas', 'trocas_movimento_unique')) {
            Schema::table('trocas', function (Blueprint $table) {
                $table->dropUnique('trocas_movimento_unique');
            });
        }
    }

    public function down(): void
    {
        $hasRepeatedEntries = DB::table('produtos_nota_fiscal')
            ->select('nota_fiscal_id', 'produto_id')->groupBy('nota_fiscal_id', 'produto_id')
            ->havingRaw('COUNT(*) > 1')->exists();
        $hasRepeatedTrades = DB::table('trocas')
            ->select('produto_nota_fiscal_id', 'operacao', 'data_operacao')
            ->groupBy('produto_nota_fiscal_id', 'operacao', 'data_operacao')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($hasRepeatedEntries || $hasRepeatedTrades) {
            throw new RuntimeException('Não é possível reverter: há movimentos repetidos legítimos.');
        }

        Schema::table('produtos_nota_fiscal', function (Blueprint $table) {
            $table->unique(['nota_fiscal_id', 'produto_id']);
            $table->dropIndex('produtos_nota_fiscal_nota_fiscal_id_index');
        });
        Schema::table('trocas', function (Blueprint $table) {
            $table->unique(['produto_nota_fiscal_id', 'operacao', 'data_operacao'], 'trocas_movimento_unique');
        });
        Schema::table('trocas', function (Blueprint $table) {
            $table->dropIndex('trocas_produto_nota_fiscal_id_index');
        });
    }
};
