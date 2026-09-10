<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trocas', function (Blueprint $table) {
            $table->foreignUuid('usuario_responsavel_id')
                ->nullable()
                ->after('produto_nota_fiscal_id')
                ->constrained('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trocas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('usuario_responsavel_id');
        });
    }
};
