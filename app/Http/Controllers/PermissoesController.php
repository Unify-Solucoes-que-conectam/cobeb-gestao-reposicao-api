<?php

namespace App\Http\Controllers;

use App\Models\Filial;
use App\Models\Grupo;
use App\Models\Menu;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gerenciamento de grupos de permissão (menus + ações) e dos acessos
 * dos usuários (grupo e tenants/filiais).
 */
class PermissoesController extends Controller
{
    /** Opções para montar as telas: ações, menus e filiais. */
    public function catalogo()
    {
        $acoes = collect(config('permissoes.acoes'))
            ->map(fn ($acao, $chave) => ['chave' => $chave] + $acao)
            ->values();

        $menus = Menu::query()
            ->whereNull('menu_pai_id')
            ->with(['subMenus' => fn ($q) => $q->orderBy('ordem')])
            ->orderBy('ordem')
            ->get()
            ->map(fn (Menu $m) => [
                'id' => $m->id,
                'titulo' => $m->titulo,
                'sub_menus' => $m->subMenus->map(fn (Menu $s) => ['id' => $s->id, 'titulo' => $s->titulo])->values(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'acoes' => $acoes,
                'menus' => $menus,
                'filiais' => Filial::query()->orderBy('descricao')->get(['id', 'codigo', 'descricao']),
                'perfis' => Grupo::PERFIS,
            ],
        ]);
    }

    // ─── Grupos ──────────────────────────────────────────────────────────

    public function grupos()
    {
        $grupos = Grupo::query()->with('menus:id')->withCount('usuarios')->orderBy('nome')->get();

        return response()->json([
            'success' => true,
            'data' => $grupos->map(fn (Grupo $g) => $this->grupoResource($g)),
        ]);
    }

    public function salvarGrupo(Request $request, ?string $id = null)
    {
        $grupo = $id ? Grupo::find($id) : new Grupo();
        if (!$grupo) {
            return response()->json(['success' => false, 'message' => 'Grupo não encontrado.'], 404);
        }

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('grupos', 'nome')->ignore($grupo->id)],
            'descricao' => ['nullable', 'string', 'max:255'],
            'perfil' => ['required', Rule::in(Grupo::PERFIS)],
            'menus' => ['present', 'array'],
            'menus.*' => ['string', 'exists:menus,id'],
            'permissoes' => ['present', 'array'],
            'permissoes.*' => ['string', Rule::in(array_keys(config('permissoes.acoes')))],
        ], [
            'nome.unique' => 'Já existe um grupo com este nome.',
        ]);

        // o perfil de um grupo de sistema não muda (são a base dos perfis do app)
        if ($grupo->sistema && $dados['perfil'] !== $grupo->perfil) {
            return response()->json(['success' => false, 'message' => 'O perfil de um grupo de sistema não pode ser alterado.'], 422);
        }

        DB::transaction(function () use ($grupo, $dados) {
            $perfilAlterado = $grupo->exists && $grupo->perfil !== $dados['perfil'];

            $grupo->fill([
                'nome' => $dados['nome'],
                'descricao' => $dados['descricao'] ?? null,
                'perfil' => $dados['perfil'],
            ])->save();

            $grupo->menus()->sync($this->comMenusPai($dados['menus']));
            $grupo->sincronizarPermissoes($dados['permissoes']);

            // o perfil (role) dos usuários acompanha o grupo
            if ($perfilAlterado) {
                Usuario::query()->where('grupo_id', $grupo->id)->update(['role' => $grupo->perfil]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => $id ? 'Grupo atualizado com sucesso.' : 'Grupo criado com sucesso.',
            'data' => $this->grupoResource($grupo->load('menus:id')->loadCount('usuarios')),
        ]);
    }

    public function excluirGrupo(string $id)
    {
        $grupo = Grupo::withCount('usuarios')->find($id);

        if (!$grupo) {
            return response()->json(['success' => false, 'message' => 'Grupo não encontrado.'], 404);
        }
        if ($grupo->sistema) {
            return response()->json(['success' => false, 'message' => 'Grupos de sistema não podem ser excluídos.'], 422);
        }
        if ($grupo->usuarios_count > 0) {
            return response()->json(['success' => false, 'message' => 'Mova os usuários deste grupo antes de excluí-lo.'], 422);
        }

        $grupo->delete();

        return response()->json(['success' => true, 'message' => 'Grupo excluído com sucesso.']);
    }

    // ─── Acessos dos usuários ────────────────────────────────────────────

    public function usuarios(Request $request)
    {
        $usuarios = Usuario::query()
            ->with(['grupo:id,nome,perfil', 'filiais:id,codigo,descricao'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(fn ($q) => $q->where('nome', 'like', "%{$search}%")->orWhere('cpf', 'like', "%{$search}%"));
            })
            ->when($request->filled('grupo'), fn ($q) => $q->where('grupo_id', $request->input('grupo')))
            ->orderBy('nome')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $usuarios->map(fn (Usuario $u) => $this->usuarioResource($u)),
        ]);
    }

    public function salvarUsuario(Request $request, string $id)
    {
        $usuario = Usuario::find($id);
        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'Usuário não encontrado.'], 404);
        }

        $dados = $request->validate([
            'grupo_id' => ['required', 'exists:grupos,id'],
            'acesso_geral' => ['required', 'boolean'],
            'filiais' => ['present', 'array'],
            'filiais.*' => ['string', 'exists:filiais,id'],
        ]);

        // evita que o administrador remova o próprio acesso administrativo
        $novoPerfil = Grupo::find($dados['grupo_id'])->perfil;
        if ($usuario->id === $request->user()->id && $usuario->isAdministrador() && $novoPerfil !== 'administrador') {
            return response()->json(['success' => false, 'message' => 'Você não pode remover o seu próprio acesso de administrador.'], 422);
        }

        DB::transaction(function () use ($usuario, $dados) {
            $usuario->grupo_id = $dados['grupo_id'];
            $usuario->acesso_geral = $dados['acesso_geral'];
            $usuario->save();
            $usuario->filiais()->sync($dados['filiais']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Acessos do usuário atualizados com sucesso.',
            'data' => $this->usuarioResource($usuario->fresh(['grupo:id,nome,perfil', 'filiais:id,codigo,descricao'])),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** Ao liberar um submenu, o menu pai (agrupador) também precisa estar liberado. */
    private function comMenusPai(array $menuIds): array
    {
        $pais = Menu::query()->whereIn('id', $menuIds)->whereNotNull('menu_pai_id')->pluck('menu_pai_id')->all();

        return array_values(array_unique(array_merge($menuIds, $pais)));
    }

    private function grupoResource(Grupo $grupo): array
    {
        return [
            'id' => $grupo->id,
            'nome' => $grupo->nome,
            'descricao' => $grupo->descricao,
            'perfil' => $grupo->perfil,
            'sistema' => $grupo->sistema,
            'usuarios_count' => $grupo->usuarios_count ?? 0,
            'menus' => $grupo->menus->pluck('id')->values(),
            'permissoes' => $grupo->perfil === 'administrador' ? array_keys(config('permissoes.acoes')) : $grupo->permissoes(),
        ];
    }

    private function usuarioResource(Usuario $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nome' => $usuario->nome,
            'cpf' => $usuario->cpf,
            'role' => $usuario->role,
            'grupo' => $usuario->grupo ? ['id' => $usuario->grupo->id, 'nome' => $usuario->grupo->nome, 'perfil' => $usuario->grupo->perfil] : null,
            'acesso_geral' => (bool) $usuario->acesso_geral,
            'filiais' => $usuario->filiais->map(fn ($f) => ['id' => $f->id, 'codigo' => $f->codigo, 'descricao' => $f->descricao])->values(),
        ];
    }
}
