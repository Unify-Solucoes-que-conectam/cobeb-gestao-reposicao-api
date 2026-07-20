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
        'mapa_id',
        'status',
        'usuario_responsavel_id',
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

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_responsavel_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function mapa()
    {
        return $this->belongsTo(Mapa::class, 'mapa_id');
    }

    public function notasFiscais()
    {
        return $this->hasMany(NotasFiscaisAvaria::class, 'avaria_id');
    }

    public function produtos()
    {
        return $this->hasMany(ProdutosAvaria::class, 'avaria_id');
    }

    public function anexos()
    {
        return $this->hasMany(AnexosAvaria::class, 'avaria_id');
    }
    protected static function gerarIdUnico()
    {
        do {
            $id = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        } while (self::where('id', $id)->exists());

        return $id;
    }
}
