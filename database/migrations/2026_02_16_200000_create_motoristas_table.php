<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motoristas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo')->unique();
            $table->foreignUuid('filial_id')->nullable()->constrained('filiais')->nullOnDelete();
            $table->foreignUuid('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->enum('status', ['ativo', 'inativo', 'bloqueado'])->default('ativo');
            $table->date('data_admissao')->nullable();
            $table->date('data_inativacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motoristas');
    }
};
