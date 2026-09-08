<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('avaria_whatsapp_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('avaria_id', 8);
            $table->uuid('correlation_id')->index();
            $table->string('event', 32);
            $table->string('status', 24)->default('unknown')->index();
            $table->string('phone', 13)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->foreignUuid('corrected_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('avaria_id')->references('id')->on('avarias')->cascadeOnDelete();
            $table->unique(['avaria_id', 'event'], 'avaria_whatsapp_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaria_whatsapp_notifications');
    }
};
