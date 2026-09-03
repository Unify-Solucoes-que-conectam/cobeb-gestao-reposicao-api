<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE usuarios MODIFY role ENUM('administrador','monitoramento','motorista') NOT NULL DEFAULT 'motorista'");
        }
        else {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->enum('role', ['administrador', 'monitoramento', 'motorista'])
                    ->default('motorista')
                    ->change()
                ;
            });
        }

        DB::table('usuarios')->where('cpf', '00000000000')->update(['role' => 'administrador']);
    }

    public function down(): void
    {
        DB::table('usuarios')->where('role', 'administrador')->update(['role' => 'monitoramento']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE usuarios MODIFY role ENUM('monitoramento','motorista') NOT NULL DEFAULT 'motorista'");
        }
        else {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->enum('role', ['monitoramento', 'motorista'])
                    ->default('motorista')
                    ->change()
                ;
            });
        }
    }
};
