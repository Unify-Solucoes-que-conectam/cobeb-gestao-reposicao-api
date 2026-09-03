<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Filial extends Model
{
    use HasUuids;

    protected $table = 'filiais';

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
            'codigo' => ['required', 'unique:filiais,codigo'],
            'descricao' => ['required'],
        ];
    }

    public static function messages(): array
    {
        return [
            'codigo.required' => 'O código da filial é obrigatório.',
            'codigo.unique' => 'O código da filial já está cadastrado.',
            'descricao.required' => 'A descrição da filial é obrigatória.',
        ];
    }

    public function whatsappConfiguration()
    {
        return $this->hasOne(WhatsAppConfiguration::class);
    }
}
