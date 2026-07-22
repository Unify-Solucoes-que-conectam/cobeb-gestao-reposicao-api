<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemAvaria extends Model
{
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
