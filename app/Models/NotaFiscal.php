<?php

namespace App\Models;

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
        'data_emissao',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function produtos()
    {
        return $this->hasMany(ProdutoNotaFiscal::class, 'nota_fiscal_id');
    }
}
