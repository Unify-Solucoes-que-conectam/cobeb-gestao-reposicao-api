<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('mapas', function (Blueprint $table) {
      $table->uuid('id')->primary();
      $table->string('codigo')->unique();
      $table->foreignUuid('motorista_id')->nullable()->constrained('motoristas')->nullOnDelete();
      $table->foreignUuid('filial_id')->nullable()->constrained('filiais')->nullOnDelete();
      $table->dateTimeTz('data_entrega');
      $table->string('placa');
      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('mapas');
  }
};
