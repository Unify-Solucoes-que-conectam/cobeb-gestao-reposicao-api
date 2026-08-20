<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacao extends Model
{
    protected $table = 'notificacoes';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'titulo',
        'mensagem',
        'tipo',
        'link',
        'data_envio',
        'data_leitura',
        'menu_id',
        'usuario_id',
    ];

    protected $casts = [
        'data_envio' => 'datetime',
        'data_leitura' => 'datetime',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
