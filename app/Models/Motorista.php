<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Motorista extends Model
{
    use HasUuids;

    protected $table = 'motoristas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'codigo',
        'filial_id',
        'cluster_id',
        'usuario_id',
        'status',
        'data_admissao',
        'data_inativacao'
    ];

    protected $casts = [
        'data_admissao' => 'date',
        'data_inativacao' => 'date',
    ];

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            // regras para criação de usuário
            'nome' => ['required'],
            'cpf' => ['required', 'unique:usuarios,cpf'],

            // regras para criação de motorista
            'codigo' => ['required', 'unique:motoristas,codigo'],
            'filial_id' => ['nullable', 'exists:filiais,id'],
            'cluster_id' => ['nullable', 'exists:clusters,id'],
            'status' => ['nullable', 'in:ativo,inativo,bloqueado'],
            'data_admissao' => ['nullable', 'date'],
            'data_inativacao' => ['nullable', 'date'],
        ];
    }

    public static function updateRules($motoristaId = null, $usuarioId = null): array
    {
        return [
            'nome' => ['required', 'filled'],
            'cpf' => [
                'required',
                'string',
                Rule::unique('usuarios', 'cpf')->ignore($usuarioId)
            ],
            'codigo' => [
                'required',
                'string',
                Rule::unique('motoristas', 'codigo')->ignore($motoristaId)
            ],
            'filial_id' => ['required', 'nullable', 'exists:filiais,id'],
            'cluster_id' => ['required', 'nullable', 'exists:clusters,id'],
            'status' => ['required', 'in:ativo,inativo,bloqueado'],
            'data_admissao' => ['nullable', 'date'],
            'data_inativacao' => ['nullable', 'date'],
        ];
    }

    public static function messages(): array
    {
        return [
            'nome.filled' => 'O nome precisa ser preenchido.',
            'cpf.filled' => 'O CPF precisa ser preenchido.',
            'cpf.unique' => 'O CPF já está cadastrado.',
            'codigo.filled' => 'O código do motorista precisa ser preenchido.',
            'codigo.unique' => 'O código do motorista já está cadastrado.',
            'codigo.nullable' => 'O código do motorista não pode ser nulo.',
            'filial_id.exists' => 'O ID da filial informado não existe.',
            'cluster_id.exists' => 'O ID do cluster informado não existe.',
            'status.in' => 'O status deve ser "ativo", "inativo" ou "bloqueado".',
            'data_admissao.date' => 'A data de admissão deve ser uma data válida.',
            'data_inativacao.date' => 'A data de inativação deve ser uma data válida.',
        ];
    }

    public function mapas()
    {
        return $this->hasMany(Mapa::class, 'motorista_id');
    }

    public function mapaAtual()
    {
        return $this->hasOne(Mapa::class, 'motorista_id')->where('data_entrega', '=', today());
    }

    public function filial()
    {
        return $this->belongsTo(Filial::class);
    }

    public function cluster()
    {
        return $this->belongsTo(Cluster::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
