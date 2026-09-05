<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('filial_id')->unique()->constrained('filiais')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('instance_name')->unique();
            $table->string('instance_id')->nullable();
            $table->text('instance_api_key')->nullable();
            $table->text('meta_access_token')->nullable();
            $table->string('meta_phone_number_id')->nullable();
            $table->string('meta_business_account_id')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 30)->default('creating');
            $table->string('connected_phone', 30)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_configurations');
    }
};
