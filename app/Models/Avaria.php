<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Avaria extends Model
{
    protected $table = 'avarias';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'cliente_id',
        'motorista_id',
        'status',
        'data_emissao',
        'aprovador_id',
        'data_aprovacao',
        'motivo_reprovacao'
    ];

    // --- Validation Rules ---
    public static function createRules(): array
    {
        return [
            'cliente_id' => 'required|exists:clientes,id',
            'motorista_id' => 'required|exists:motoristas,id',

            'produtos' => ['required', 'array', 'min:1'],

            // Valida cada item individualmente dentro do array
            'produtos.*.produto_id' => ['required', 'exists:produtos_nota_fiscal,id'],
            'produtos.*.tipo_avaria_id' => ['required', 'exists:tipos_avaria,id'],
            'produtos.*.quantidade' => ['required', 'integer', 'min:1'],

            'anexos' => ['nullable', 'array'],
            'anexos.*.nome' => ['required', 'string'],
            'anexos.*.base64' => ['required', 'string'],
        ];
    }

    public static function messages(): array
    {
        return [
            'cliente_id.required' => 'O campo cliente é obrigatório.',
            'cliente_id.exists' => 'O cliente selecionado não existe.',
            'motorista_id.required' => 'O campo motorista é obrigatório.',
            'motorista_id.exists' => 'O motorista selecionado não existe.',

            'produtos.required' => 'O campo produtos é obrigatório.',
            'produtos.array' => 'O campo produtos deve ser um array.',
            'produtos.min' => 'O campo produtos deve conter pelo menos um item.',
            'produtos.*.produto_id.required' => 'O campo produto é obrigatório.',
            'produtos.*.produto_id.exists' => 'O produto selecionado não existe.',
            'produtos.*.tipo_avaria_id.required' => 'O campo tipo avaria é obrigatório.',
            'produtos.*.tipo_avaria_id.exists' => 'O tipo avaria selecionado não existe.',
            'produtos.*.quantidade.required' => 'O campo quantidade é obrigatório.',
            'produtos.*.quantidade.integer' => 'O campo quantidade deve ser um número inteiro.',
            'produtos.*.quantidade.min' => 'O campo quantidade deve ser no mínimo 1.',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Antes de criar um registro novo, gera o ID
        static::creating(function ($avaria) {
            if (empty($avaria->id)) {
                $avaria->id = self::gerarIdUnico();
            }
        });
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function motorista()
    {
        return $this->belongsTo(Motorista::class, 'motorista_id');
    }

    public function aprovador()
    {
        return $this->belongsTo(Usuario::class, 'aprovador_id');
    }

    public function anexos()
    {
        return $this->hasMany(AnexosAvaria::class, 'avaria_id');
    }

    public function itens()
    {
        return $this->hasMany(ItemAvaria::class, 'avaria_id');
    }

    /**
     * Accessor para retornar a Nota Fiscal da Avaria
     * Baseia-se no primeiro item, já que a avaria geralmente pertence a uma mesma nota.
     */
    public function getNotaFiscalAttribute()
    {
        return $this->itens->first()?->produtoNotaFiscal?->notaFiscal;
    }

    protected static function gerarIdUnico()
    {
        do {
            $id = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        } while (self::where('id', $id)->exists());

        return $id;
    }
}
