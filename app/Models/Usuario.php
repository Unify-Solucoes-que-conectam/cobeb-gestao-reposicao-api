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
        'primeiro_acesso',
    ];

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cpf' => ['required', 'string', 'max:11', 'unique:usuarios,cpf'],
            'senha' => ['required', 'string'],
            'confirmar_senha' => ['required', 'same:senha'],
            'role' => ['required', 'in:administrador,monitoramento,motorista'],
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
