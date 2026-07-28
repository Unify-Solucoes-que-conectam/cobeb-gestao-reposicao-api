<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('notas_fiscais', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->string('numero')->unique();
      $table->string('pedido')->nullable();
      $table->foreignUuid('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
      $table->date('data_emissao')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('notas_fiscais');
  }
};
