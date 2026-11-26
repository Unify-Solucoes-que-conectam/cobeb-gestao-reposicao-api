<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada revenda (filial) possui sua própria numeração de motoristas, clientes (PDV) e mapas.
 * Com o "codigo" único global, importar a base de uma revenda sobrescrevia os registros
 * da outra. A partir daqui a unicidade passa a ser (codigo, filial_id).
 */
return new class extends Migration
{
    private const TABLES = ['motoristas', 'clientes', 'mapas'];

    public function up(): void
    {
        // DDL não é transacional no MySQL: cada passo só roda se ainda for necessário.
        foreach (self::TABLES as $table) {
            if (! Schema::hasIndex($table, "{$table}_codigo_filial_id_unique")) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unique(['codigo', 'filial_id']);
                });
            }
            if (Schema::hasIndex($table, "{$table}_codigo_unique")) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropUnique(['codigo']);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $repeated = DB::table($table)->select('codigo')->groupBy('codigo')->havingRaw('COUNT(*) > 1')->exists();
            if ($repeated) {
                throw new RuntimeException("Não é possível reverter: a tabela {$table} possui o mesmo código em mais de uma filial.");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unique(['codigo']);
                $t->dropUnique(['codigo', 'filial_id']);
            });
        }
    }
};
