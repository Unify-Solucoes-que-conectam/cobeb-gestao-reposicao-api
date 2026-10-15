<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_nota_fiscal', function (Blueprint $table) {
            // No MySQL, o índice único composto era também o índice exigido pela
            // FK de nota_fiscal_id; crie a cobertura simples antes de removê-lo.
            $table->index('nota_fiscal_id', 'produtos_nota_fiscal_nota_fiscal_id_index');
            $table->dropUnique(['nota_fiscal_id', 'produto_id']);
        });

        Schema::table('trocas', function (Blueprint $table) {
            $table->dropUnique('trocas_movimento_unique');
        });
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
    }
};
