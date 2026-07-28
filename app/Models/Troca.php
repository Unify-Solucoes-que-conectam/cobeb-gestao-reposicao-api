<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Troca extends Model
{
    use HasUuids;

    protected $table = 'trocas';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'produto_nota_fiscal_id',
        'quantidade',
        'operacao',
        'data_operacao',
    ];

    public function produtoNotaFiscal()
    {
        return $this->belongsTo(ProdutoNotaFiscal::class, 'produto_nota_fiscal_id');
    }
}
