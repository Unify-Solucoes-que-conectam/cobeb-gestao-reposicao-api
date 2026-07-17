<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avarias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignUuid('mapa_id')->nullable()->constrained('mapas')->nullOnDelete();
            $table->enum('status', ['pendente', 'reprovado', 'aprovado'])->default('pendente');
            $table->foreignUuid('usuario_responsavel_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avarias');
    }
};
