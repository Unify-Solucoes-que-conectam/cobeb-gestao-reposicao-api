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

    protected static function gerarIdUnico()
    {
        do {
            $id = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        } while (self::where('id', $id)->exists());

        return $id;
    }
}
