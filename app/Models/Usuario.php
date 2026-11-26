<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * Class Usuario
 *
 * @property string $id
 * @property string $nome
 * @property string|null $senha
 * @property string|null $email
 * @property string|null $foto_perfil
 * @property bool $status
 * @property string|null $tipo
 */
class Usuario extends Authenticatable
{
    use CanResetPassword, HasApiTokens, HasUuids, Notifiable;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'usuarios';

    protected $hidden = [
        'senha',
    ];

    protected $fillable = [
        'nome',
        'cpf',
        'senha',
        'role',
        'grupo_id',
        'acesso_geral',
        'primeiro_acesso',
    ];

    protected $casts = [
        'acesso_geral' => 'boolean',
    ];

    /** Cache das ações do grupo durante a requisição. */
    private ?array $permissoesCache = null;

    protected static function booted(): void
    {
        // O grupo é a fonte do perfil (role). Telas antigas que só enviam o role
        // recebem o grupo de sistema correspondente.
        static::saving(function (Usuario $usuario) {
            if ($usuario->isDirty('grupo_id') && $usuario->grupo_id) {
                $usuario->attributes['role'] = Grupo::query()->whereKey($usuario->grupo_id)->value('perfil') ?? $usuario->role;
            }
            elseif (($usuario->isDirty('role') || !$usuario->grupo_id) && $usuario->role) {
                $atual = $usuario->grupo_id ? Grupo::find($usuario->grupo_id) : null;
                if (!$atual || $atual->perfil !== $usuario->role) {
                    $usuario->grupo_id = Grupo::doPerfil($usuario->role)?->id;
                }
            }
        });

        static::saved(fn (Usuario $usuario) => $usuario->permissoesCache = null);
    }

    // --- Grupos, permissões e tenants ---

    public function grupo()
    {
        return $this->belongsTo(Grupo::class, 'grupo_id');
    }

    public function filiais()
    {
        return $this->belongsToMany(Filial::class, 'usuario_filiais', 'usuario_id', 'filial_id');
    }

    public function isAdministrador(): bool
    {
        return $this->role === 'administrador';
    }

    /** Chaves das ações liberadas (administrador possui todas). */
    public function permissoes(): array
    {
        if ($this->isAdministrador()) {
            return array_keys(config('permissoes.acoes'));
        }

        return $this->permissoesCache ??= ($this->grupoEfetivo()?->permissoes() ?? []);
    }

    /** Grupo do usuário; sem grupo vinculado, usa o grupo de sistema do perfil (role). */
    public function grupoEfetivo(): ?Grupo
    {
        return $this->grupo ?? ($this->role ? Grupo::doPerfil($this->role) : null);
    }

    public function temPermissao(string $permissao): bool
    {
        return in_array($permissao, $this->permissoes(), true);
    }

    /** Acesso ao tenant "Geral" (todas as filiais). Administrador sempre possui. */
    public function temAcessoGeral(): bool
    {
        return $this->isAdministrador() || (bool) $this->acesso_geral;
    }

    /** Filiais que o usuário pode selecionar como tenant. */
    public function filiaisPermitidas()
    {
        return $this->temAcessoGeral()
            ? Filial::query()->orderBy('descricao')->get()
            : $this->filiais()->orderBy('descricao')->get();
    }

    /** Menus visíveis (raízes com submenus) conforme o grupo. */
    public function menusPermitidos()
    {
        $ids = $this->isAdministrador()
            ? null
            : ($this->grupoEfetivo()?->menus()->pluck('menus.id')->all() ?? []);

        $permitido = fn ($q) => $ids === null ? $q : $q->whereIn('id', $ids);

        return Menu::query()
            ->with(['subMenus' => fn ($q) => $permitido($q)->orderBy('ordem')])
            ->whereNull('menu_pai_id')
            ->when($ids !== null, fn ($query) => $query->where(
                fn ($q) => $q->whereIn('id', $ids)->orWhereHas('subMenus', $permitido)
            ))
            ->orderBy('ordem')
            ->get()
            // agrupadores (sem rota própria) só aparecem se tiverem submenus liberados
            ->reject(fn (Menu $menu) => in_array($menu->rota, [null, '', '#'], true) && $menu->subMenus->isEmpty())
            ->values();
    }

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cpf' => ['required', 'string', 'max:11', 'unique:usuarios,cpf'],
            'senha' => ['required', 'string'],
            'confirmar_senha' => ['required', 'same:senha'],
            'role' => ['required_without:grupo_id', 'in:administrador,monitoramento,motorista'],
            'grupo_id' => ['nullable', 'exists:grupos,id'],
        ];
    }

    public static function messages(): array
    {
        return [
            'senha.required' => 'A senha é obrigatória.',
            'senha.string' => 'A senha deve ser do tipo texto.',
            'confirmar_senha.required' => 'A confirmação de senha é obrigatória.',
            'confirmar_senha.same' => 'As senhas não coincidem.',
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.string' => 'O CPF deve ser do tipo texto.',
            'cpf.max' => 'O CPF não pode ter mais de 11 caracteres.',
            'cpf.unique' => 'O CPF informado já está em uso.',
            'nome.required' => 'O nome é obrigatório.',
            'nome.string' => 'O nome deve ser do tipo texto.',
            'nome.max' => 'O nome não pode ter mais de 100 caracteres.',
            'role.required' => 'O tipo de usuário é obrigatório.',
        ];
    }

    // --- Accessors ---

    // --- Mutators ---

    public function setNomeAttribute($value)
    {
        $this->attributes['nome'] = mb_strtoupper($value);
    }

    public function setSenhaAttribute($value)
    {
        $this->attributes['senha'] = Hash::make($value);
    }

    public function setCpfAttribute($value)
    {
        $this->attributes['cpf'] = mb_strtolower($value);
    }

    public function setRoleAttribute($value)
    {
        $this->attributes['role'] = mb_strtolower($value) ?: 1;
    }

    // --- JWT Methods ---

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getAuthPasswordName()
    {
        return 'senha';
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => (string) $this->id,
            'nome' => $this->nome,
            'cpf' => $this->cpf,
            'role' => $this->role ?? null,
            'primeiro_acesso' => $this->primeiro_acesso,
        ];
    }

    public function notificacoes()
    {
        return $this->hasMany(Notificacao::class, 'usuario_id');
    }

    public function menus_favoritos()
    {
        return $this->belongsToMany(Menu::class, 'usuarios_menus_favoritos', 'usuario_id', 'menu_id');
    }

    public function motorista()
    {
        return $this->hasOne(Motorista::class, 'usuario_id');
    }
}
