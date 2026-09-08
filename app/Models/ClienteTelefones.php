<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClienteTelefones extends Model
{
    use HasUuids;

    protected $table = 'cliente_telefones';

    public $timestamps = true;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'cliente_id',
        'numero',
        'isWhatsapp',
        'whatsapp_validation_status',
        'whatsapp_verified_at',
        'whatsapp_validation_provider',
    ];

    protected function casts(): array
    {
        return [
            'isWhatsapp' => 'boolean',
            'whatsapp_verified_at' => 'datetime',
        ];
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
