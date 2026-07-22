<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasUuids;

    protected $table = 'clientes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'filial_id',
        'categoria_id',
        'codigo',
        'documento',
        'nome_fantasia',
        'razao_social',
        'endereco',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'cep',
        'latitude',
        'longitude',
        'status',
        'tipo_pessoa',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function filial()
    {
        return $this->belongsTo(Filial::class);
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function notasFiscais()
    {
        return $this->hasMany(NotaFiscal::class, 'cliente_id');
    }
}
