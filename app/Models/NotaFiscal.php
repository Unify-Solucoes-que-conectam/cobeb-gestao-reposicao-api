<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotaFiscal extends Model
{
    use HasUuids;

    protected $table = 'notas_fiscais';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'numero',
        'pedido',
        'cliente_id',
        'filial_id',
        'data_emissao',
    ];

    protected static function booted(): void
    {
        // O número da nota é único por filial (revenda); a filial da nota é a do cliente.
        static::saving(function (NotaFiscal $nota) {
            if (!$nota->filial_id && $nota->cliente_id) {
                $nota->filial_id = Cliente::query()->whereKey($nota->cliente_id)->value('filial_id');
            }
        });
    }

    /** Nota pelo número dentro da filial informada (null = notas sem filial). */
    public function scopeNumeroNaFilial(Builder $query, string $numero, ?string $filialId): Builder
    {
        return $query->where('numero', $numero)
            ->when($filialId, fn ($q) => $q->where('filial_id', $filialId), fn ($q) => $q->whereNull('filial_id'));
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function filial()
    {
        return $this->belongsTo(Filial::class);
    }

    public function produtos()
    {
        return $this->hasMany(ProdutoNotaFiscal::class, 'nota_fiscal_id');
    }
}
