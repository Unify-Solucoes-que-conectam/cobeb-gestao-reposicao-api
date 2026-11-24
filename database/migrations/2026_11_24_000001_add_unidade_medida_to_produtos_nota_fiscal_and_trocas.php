<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidade de medida informada no importador de Vendas e Trocas (CX, DZ, UN...).
        // Nullable para preservar os registros importados antes desta alteração.
        if (! Schema::hasColumn('produtos_nota_fiscal', 'unidade_medida')) {
            Schema::table('produtos_nota_fiscal', function (Blueprint $table) {
                $table->string('unidade_medida', 10)->nullable()->after('quantidade');
            });
        }
        if (! Schema::hasColumn('trocas', 'unidade_medida')) {
            Schema::table('trocas', function (Blueprint $table) {
                $table->string('unidade_medida', 10)->nullable()->after('quantidade');
            });
        }
    }

    public function down(): void
    {
        Schema::table('produtos_nota_fiscal', fn (Blueprint $table) => $table->dropColumn('unidade_medida'));
        Schema::table('trocas', fn (Blueprint $table) => $table->dropColumn('unidade_medida'));
    }
};
