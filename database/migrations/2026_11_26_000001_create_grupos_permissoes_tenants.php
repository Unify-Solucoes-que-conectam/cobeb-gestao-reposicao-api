<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Grupos de permissão (substituem os roles), permissões de menus/ações por grupo
 * e tenants (filiais) liberadas por usuário.
 *
 * Os roles atuais viram grupos de sistema e todos os usuários são migrados sem
 * perda de acesso: administradores e monitoramento recebem acesso "Geral".
 */
return new class extends Migration
{
    private const MONITORAMENTO_ACOES = [
        'importacoes.importar',
        'mapas.designar_motorista',
        'mapas.alterar_clientes',
        'motoristas.gerenciar',
        'trocas.exportar',
    ];

    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 100)->unique();
            $table->string('descricao')->nullable();
            // perfil define a área do app usada pelo grupo (painel administrativo ou app do motorista)
            $table->enum('perfil', ['administrador', 'monitoramento', 'motorista'])->default('monitoramento');
            $table->boolean('sistema')->default(false);
            $table->timestamps();
        });

        Schema::create('grupo_menus', function (Blueprint $table) {
            $table->foreignUuid('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignUuid('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->primary(['grupo_id', 'menu_id']);
        });

        Schema::create('grupo_permissoes', function (Blueprint $table) {
            $table->foreignUuid('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->string('permissao', 100);
            $table->primary(['grupo_id', 'permissao']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignUuid('grupo_id')->nullable()->after('role')->constrained('grupos')->nullOnDelete();
            $table->boolean('acesso_geral')->default(false)->after('grupo_id');
        });

        Schema::create('usuario_filiais', function (Blueprint $table) {
            $table->foreignUuid('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignUuid('filial_id')->constrained('filiais')->cascadeOnDelete();
            $table->primary(['usuario_id', 'filial_id']);
        });

        $this->seed();
    }

    private function seed(): void
    {
        $now = now();

        // menu de permissões dentro de "Gerenciar"
        $gerenciarId = DB::table('menus')->where('titulo', 'Gerenciar')->value('id');
        if ($gerenciarId && !DB::table('menus')->where('rota', '/admin/gerenciar/permissoes')->exists()) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(), 'titulo' => 'Permissões', 'icone' => 'ShieldCheck',
                'rota' => '/admin/gerenciar/permissoes', 'ordem' => 5, 'menu_pai_id' => $gerenciarId,
                'required_role' => 'administrador', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $grupos = [
            'administrador' => ['nome' => 'Administradores', 'descricao' => 'Acesso total ao sistema.'],
            'monitoramento' => ['nome' => 'Monitoramento', 'descricao' => 'Equipe de monitoramento.'],
            'motorista' => ['nome' => 'Motoristas', 'descricao' => 'Acesso ao aplicativo do motorista.'],
        ];

        $todasAcoes = array_keys(config('permissoes.acoes'));

        foreach ($grupos as $perfil => $dados) {
            $grupoId = (string) Str::uuid();
            DB::table('grupos')->insert($dados + [
                'id' => $grupoId, 'perfil' => $perfil, 'sistema' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            // menus: mesma visibilidade que o required_role dava até aqui
            $menus = DB::table('menus')
                ->where(fn ($q) => $q->whereNull('required_role')->orWhere('required_role', $perfil))
                ->pluck('id');
            DB::table('grupo_menus')->insert($menus->map(fn ($menuId) => ['grupo_id' => $grupoId, 'menu_id' => $menuId])->all());

            $acoes = match ($perfil) {
                'administrador' => $todasAcoes,
                'monitoramento' => self::MONITORAMENTO_ACOES,
                default => [],
            };
            if ($acoes) {
                DB::table('grupo_permissoes')->insert(array_map(fn ($acao) => ['grupo_id' => $grupoId, 'permissao' => $acao], $acoes));
            }

            DB::table('usuarios')->where('role', $perfil)->update([
                'grupo_id' => $grupoId,
                'acesso_geral' => $perfil !== 'motorista',
            ]);
        }

        // motoristas: tenant = filial do cadastro de motorista
        $vinculos = DB::table('motoristas')->whereNotNull('filial_id')
            ->select('usuario_id', 'filial_id')->distinct()->get()
            ->map(fn ($m) => ['usuario_id' => $m->usuario_id, 'filial_id' => $m->filial_id])->all();
        if ($vinculos) {
            DB::table('usuario_filiais')->insertOrIgnore($vinculos);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_filiais');
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grupo_id');
            $table->dropColumn('acesso_geral');
        });
        Schema::dropIfExists('grupo_permissoes');
        Schema::dropIfExists('grupo_menus');
        Schema::dropIfExists('grupos');
        DB::table('menus')->where('rota', '/admin/gerenciar/permissoes')->delete();
    }
};
