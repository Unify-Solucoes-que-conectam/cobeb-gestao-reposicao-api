<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('avarias', function (Blueprint $table) {
            $table->string('whatsapp_notification_status')->nullable()->after('motivo_reprovacao');
            $table->string('whatsapp_notification_phone', 20)->nullable()->after('whatsapp_notification_status');
            $table->text('whatsapp_notification_error')->nullable()->after('whatsapp_notification_phone');
            $table->timestamp('whatsapp_notification_sent_at')->nullable()->after('whatsapp_notification_error');
        });
    }

    public function down(): void
    {
        Schema::table('avarias', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_notification_status',
                'whatsapp_notification_phone',
                'whatsapp_notification_error',
                'whatsapp_notification_sent_at',
            ]);
        });
    }
};
