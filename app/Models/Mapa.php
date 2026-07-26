<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Mapa extends Model
{
    use HasUuids;

    protected $table = 'mapas';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'codigo',
        'motorista_id',
        'filial_id',
        'data_entrega',
        'placa'
    ];

    public function filial()
    {
        return $this->belongsTo(Filial::class, 'filial_id');
    }

    public function motorista()
    {
        return $this->belongsTo(Motorista::class, 'motorista_id');
    }

    public function clientes()
    {
        return $this->hasMany(ClientesMapa::class, 'mapa_id');
    }
}
