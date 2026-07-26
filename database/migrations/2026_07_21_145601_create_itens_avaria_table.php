<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itens_avaria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('avaria_id')->constrained('avarias')->onDelete('cascade');
            $table->foreignUuid('produto_nota_fiscal_id')->constrained('produtos_nota_fiscal')->onDelete('cascade');
            $table->foreignUuid('tipo_avaria_id')->constrained('tipos_avaria')->onDelete('cascade');
            $table->integer('quantidade_avariada')->unsigned()->check('quantidade_avariada >= 1');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens_avaria');
    }
};
