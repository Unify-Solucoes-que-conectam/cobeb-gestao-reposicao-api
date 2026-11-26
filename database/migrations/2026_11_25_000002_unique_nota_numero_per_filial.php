<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada revenda (filial) possui sua própria numeração de notas fiscais.
 * A nota passa a guardar a filial (a mesma do cliente) e a unicidade vira (numero, filial_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // DDL não é transacional no MySQL: cada passo só roda se ainda for necessário.
        if (! Schema::hasColumn('notas_fiscais', 'filial_id')) {
            Schema::table('notas_fiscais', function (Blueprint $table) {
                $table->foreignUuid('filial_id')->nullable()->after('cliente_id')->constrained('filiais')->nullOnDelete();
            });
        }

        // a filial da nota é a filial do cliente
        DB::table('notas_fiscais')->whereNull('filial_id')->update([
            'filial_id' => DB::raw('(SELECT clientes.filial_id FROM clientes WHERE clientes.id = notas_fiscais.cliente_id)'),
        ]);

        if (! Schema::hasIndex('notas_fiscais', 'notas_fiscais_numero_filial_id_unique')) {
            Schema::table('notas_fiscais', function (Blueprint $table) {
                $table->unique(['numero', 'filial_id']);
            });
        }
        if (Schema::hasIndex('notas_fiscais', 'notas_fiscais_numero_unique')) {
            Schema::table('notas_fiscais', function (Blueprint $table) {
                $table->dropUnique(['numero']);
            });
        }
    }

    public function down(): void
    {
        $repeated = DB::table('notas_fiscais')->select('numero')->groupBy('numero')->havingRaw('COUNT(*) > 1')->exists();
        if ($repeated) {
            throw new RuntimeException('Não é possível reverter: há notas com o mesmo número em mais de uma filial.');
        }

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->unique(['numero']);
            $table->dropUnique(['numero', 'filial_id']);
        });
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filial_id');
        });
    }
};
