<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_configurations', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('filial_id')->index();
            $table->string('global_slot', 20)->nullable()->unique()->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configurations', function (Blueprint $table) {
            $table->dropColumn(['is_global', 'global_slot']);
        });
    }
};
