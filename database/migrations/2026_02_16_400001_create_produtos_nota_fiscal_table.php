<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('produtos_nota_fiscal', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->foreignUuid('nota_fiscal_id')->constrained('notas_fiscais')->cascadeOnDelete();
      $table->foreignUuid('produto_id')->constrained('produtos')->cascadeOnDelete();
      $table->integer('quantidade')->default(0);
      $table->decimal('valor_desconto', 12, 2)->default(0);
      $table->decimal('valor_adicional', 12, 2)->default(0);
      $table->decimal('valor_total', 12, 2)->default(0);
      $table->timestamps();

      $table->unique(['nota_fiscal_id', 'produto_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('produtos_nota_fiscal');
  }
};
