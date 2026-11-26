<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ações de avarias. Os grupos existentes recebem as ações que já usavam,
 * de acordo com o perfil, para que ninguém perca acesso.
 */
return new class extends Migration
{
    private const POR_PERFIL = [
        'administrador' => ['avarias.registrar', 'avarias.aprovar', 'avarias.editar_quantidade', 'avarias.reenviar_whatsapp'],
        'monitoramento' => ['avarias.aprovar', 'avarias.editar_quantidade', 'avarias.reenviar_whatsapp'],
        'motorista' => ['avarias.registrar'],
    ];

    public function up(): void
    {
        foreach (DB::table('grupos')->get(['id', 'perfil']) as $grupo) {
            $acoes = self::POR_PERFIL[$grupo->perfil] ?? [];
            if ($acoes) {
                DB::table('grupo_permissoes')->insertOrIgnore(
                    array_map(fn ($acao) => ['grupo_id' => $grupo->id, 'permissao' => $acao], $acoes)
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('grupo_permissoes')->where('permissao', 'like', 'avarias.%')->delete();
    }
};
