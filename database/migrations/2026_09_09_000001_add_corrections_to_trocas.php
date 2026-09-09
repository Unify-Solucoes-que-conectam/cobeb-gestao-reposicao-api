<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Não apagar movimentos históricos para conseguir criar a restrição.
        $duplicates = DB::table('trocas')->select('produto_nota_fiscal_id', 'operacao', 'data_operacao')
            ->groupBy('produto_nota_fiscal_id', 'operacao', 'data_operacao')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Existem trocas repetidas por item, operação e data. Concilie esses registros antes de executar esta migration.');
        }
        Schema::table('trocas', function (Blueprint $table) {
            $table->unsignedInteger('correcoes')->default(0);
            $table->text('motivo_parcial')->nullable();
            // O mesmo produto aceita várias trocas; somente a mesma movimentação é única.
            $table->unique(['produto_nota_fiscal_id', 'operacao', 'data_operacao'], 'trocas_movimento_unique');
        });
        Schema::create('troca_correcoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('troca_id')->constrained('trocas');
            $table->unsignedInteger('numero');
            $table->integer('quantidade_anterior');
            $table->integer('quantidade_nova');
            $table->text('motivo_anterior')->nullable();
            $table->text('motivo_novo')->nullable();
            $table->boolean('confirmacao_extra')->default(false);
            $table->string('usuario_id')->nullable();
            $table->timestamps();
            $table->unique(['troca_id', 'numero']);
        });
        Schema::table('import_batches', function (Blueprint $table) {
            $table->json('row_errors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', fn (Blueprint $table) => $table->dropColumn('row_errors'));
        Schema::dropIfExists('troca_correcoes');
        Schema::table('trocas', function (Blueprint $table) {
            $table->dropUnique('trocas_movimento_unique');
            $table->dropColumn(['correcoes', 'motivo_parcial']);
        });
    }
};
