<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClientesMapa extends Model
{
    use HasUuids;

    protected $table = 'clientes_mapa';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mapa_id',
        'cliente_id',
    ];

    public function mapa()
    {
        return $this->belongsTo(Mapa::class, 'mapa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
