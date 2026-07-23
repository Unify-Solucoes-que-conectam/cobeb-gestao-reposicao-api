<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ItemAvaria extends Model
{

    use HasUuids;

    protected $table = 'itens_avaria';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'avaria_id',
        'produto_nota_fiscal_id',
        'tipo_avaria_id',
        'quantidade_avariada'
    ];

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            'avaria_id' => 'required|exists:avarias,id',
            'produto_nota_fiscal_id' => 'required|exists:produtos_nota_fiscal,id',
            'tipo_avaria_id' => 'required|exists:tipos_avaria,id',
            'quantidade_avariada' => 'required|integer|min:1',
        ];
    }

    public static function messages(): array
    {
        return [
            'avaria_id.required' => 'O campo avaria é obrigatório.',
            'avaria_id.exists' => 'A avaria selecionada não existe.',
            'produto_nota_fiscal_id.required' => 'O campo produto nota fiscal é obrigatório.',
            'produto_nota_fiscal_id.exists' => 'O produto nota fiscal selecionado não existe.',
            'tipo_avaria_id.required' => 'O campo tipo avaria é obrigatório.',
            'tipo_avaria_id.exists' => 'O tipo avaria selecionado não existe.',
            'quantidade_avariada.required' => 'O campo quantidade avariada é obrigatório.',
            'quantidade_avariada.integer' => 'O campo quantidade avariada deve ser um número inteiro.',
            'quantidade_avariada.min' => 'O campo quantidade avariada deve ser no mínimo 1.',
        ];
    }

    public function avaria()
    {
        return $this->belongsTo(Avaria::class, 'avaria_id');
    }

    public function produtoNotaFiscal()
    {
        return $this->belongsTo(ProdutoNotaFiscal::class, 'produto_nota_fiscal_id');
    }

    public function tipoAvaria()
    {
        return $this->belongsTo(TipoAvaria::class, 'tipo_avaria_id');
    }
}
