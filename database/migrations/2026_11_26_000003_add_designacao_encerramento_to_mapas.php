<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotas (mapas) valem apenas no dia em que foram designadas ao motorista.
 * - designado_em: quando o motorista foi vinculado ao mapa (tela ou importação)
 * - encerrado_em: quando a rota foi encerrada (rotina das 00h); o motorista_id é mantido como histórico
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapas', function (Blueprint $table) {
            $table->timestamp('designado_em')->nullable()->after('motorista_id');
            $table->timestamp('encerrado_em')->nullable()->after('designado_em');
        });

        $agora = now();

        // mapas de hoje já designados continuam valendo até as 00h
        DB::table('mapas')
            ->whereNotNull('motorista_id')
            ->whereDate('data_entrega', today())
            ->update(['designado_em' => $agora]);

        // os demais mapas com motorista já são rotas encerradas
        DB::table('mapas')
            ->whereNotNull('motorista_id')
            ->whereNull('designado_em')
            ->update(['designado_em' => DB::raw('updated_at'), 'encerrado_em' => $agora]);
    }

    public function down(): void
    {
        Schema::table('mapas', function (Blueprint $table) {
            $table->dropColumn(['designado_em', 'encerrado_em']);
        });
    }
};
