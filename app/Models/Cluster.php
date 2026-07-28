<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cluster extends Model
{
    use HasUuids;

    protected $table = 'clusters';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'codigo',
        'descricao',
    ];

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            'codigo' => ['required', 'unique:clusters,codigo'],
            'descricao' => ['required'],
        ];
    }

    public static function messages(): array
    {
        return [
            'codigo.required' => 'O código do cluster é obrigatório.',
            'codigo.unique' => 'O código do cluster já está cadastrado.',
            'descricao.required' => 'A descrição do cluster é obrigatória.',
        ];
    }
}
