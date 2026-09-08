<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('cliente_telefones', function (Blueprint $table) {
            $table->string('whatsapp_validation_status', 16)->default('unknown')->after('isWhatsapp');
            $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_validation_status');
            $table->string('whatsapp_validation_provider', 16)->nullable()->after('whatsapp_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_telefones', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_validation_status',
                'whatsapp_verified_at',
                'whatsapp_validation_provider',
            ]);
        });
    }
};
