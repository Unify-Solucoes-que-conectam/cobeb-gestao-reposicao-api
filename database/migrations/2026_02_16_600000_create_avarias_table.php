<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avarias', function (Blueprint $table) {
            $table->string('id', 8)->primary();
            $table->foreignUuid('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignUuid('mapa_id')->nullable()->constrained('mapas')->nullOnDelete();
            $table->enum('status', ['pendente', 'reprovada', 'aprovada'])->default('pendente');
            $table->foreignUuid('usuario_responsavel_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avarias');
    }
};
