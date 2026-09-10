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
        'usuario_responsavel_id',
        'quantidade',
        'operacao',
        'data_operacao',
        'correcoes',
        'motivo_parcial',
    ];

    public function produtoNotaFiscal()
    {
        return $this->belongsTo(ProdutoNotaFiscal::class, 'produto_nota_fiscal_id');
    }

    public function responsavel()
    {
        return $this->belongsTo(Usuario::class, 'usuario_responsavel_id');
    }

    public function itensAvaria()
    {
        return $this->belongsToMany(ItemAvaria::class, 'trocas_itens_avaria', 'troca_id', 'item_avaria_id')
            ->withPivot('quantidade')
            ->withTimestamps();
    }
}
