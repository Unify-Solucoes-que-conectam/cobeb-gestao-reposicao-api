<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('titulo')->nullable();
            $table->string('mensagem');
            $table->string('tipo');
            $table->text('link')->nullable();
            $table->dateTimeTz('data_envio')->useCurrent();
            $table->dateTimeTz('data_leitura')->nullable();

            $table
                ->uuid('menu_id')
                ->foreignId('menu_id')
                ->nullable()
                ->constrained('menus')
                ->cascadeOnUpdate()
                ->cascadeOnDelete()
            ;
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
