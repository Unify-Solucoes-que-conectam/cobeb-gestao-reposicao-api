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
        Schema::create('trocas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('produto_nota_fiscal_id')->constrained('produtos_nota_fiscal');
            $table->integer('quantidade');
            $table->string('operacao');
            $table->date('data_operacao');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trocas');
    }
};
