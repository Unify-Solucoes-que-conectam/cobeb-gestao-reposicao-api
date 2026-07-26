<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('produtos', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->string('codigo')->unique();
      $table->string('ean')->nullable();
      $table->string('descricao')->nullable();
      $table->decimal('preco_unitario', 12, 2)->nullable();
      $table->foreignUuid('tipo_marca_id')->nullable()->constrained('tipos_marca')->nullOnDelete();
      $table->foreignUuid('embalagem_id')->nullable()->constrained('embalagens')->nullOnDelete();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('produtos');
  }
};
