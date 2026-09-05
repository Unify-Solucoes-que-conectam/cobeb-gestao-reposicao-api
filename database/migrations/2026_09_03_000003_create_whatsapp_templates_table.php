<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('whatsapp_configuration_id')->constrained('whatsapp_configurations')->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('template_name');
            $table->string('language_code', 15)->default('pt_BR');
            $table->string('status', 30)->default('APPROVED');
            $table->timestamps();
            $table->unique(['whatsapp_configuration_id', 'event'], 'whatsapp_template_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
