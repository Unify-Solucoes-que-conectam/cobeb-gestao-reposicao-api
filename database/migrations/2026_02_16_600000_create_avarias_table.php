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
            $table->foreignUuid('motorista_id')->nullable()->constrained('motoristas')->nullOnDelete();
            $table->enum('status', ['pendente', 'enviada', 'reprovada', 'aprovada', 'trocada'])->default('pendente');
            $table->date('data_emissao');
            $table->foreignUuid('aprovador_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->date('data_aprovacao')->nullable();
            $table->string('motivo_reprovacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avarias');
    }
};
