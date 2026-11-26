<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'filial_id',
        'motorista_id',
        'designado_em',
        'encerrado_em',
        'data_entrega',
        'placa'
    ];

    protected $casts = [
        'designado_em' => 'datetime',
        'encerrado_em' => 'datetime',
    ];

    /**
     * Rota em andamento: designada hoje, com entrega hoje e ainda não encerrada.
     * Toda rota é encerrada às 00h (comando "mapas:encerrar"); a checagem por data
     * garante a regra mesmo que a rotina das 00h atrase.
     */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query
            ->whereNotNull('motorista_id')
            ->whereNull('encerrado_em')
            ->where('designado_em', '>=', today())
            ->whereDate('data_entrega', today());
    }

    /** Vincula o motorista ao mapa, (re)abrindo a rota no dia de hoje. */
    public function designar(?string $motoristaId): void
    {
        $this->motorista_id = $motoristaId;
        $this->designado_em = $motoristaId ? now() : null;
        $this->encerrado_em = null;
    }

    public function getStatusAttribute(): string
    {
        if (!$this->motorista_id) {
            return 'nao_designado';
        }

        $ativo = !$this->encerrado_em
            && $this->designado_em?->gte(today())
            && substr((string) $this->data_entrega, 0, 10) === today()->toDateString();

        return $ativo ? 'em_andamento' : 'encerrado';
    }

    public function motorista()
    {
        return $this->belongsTo(Motorista::class, 'motorista_id');
    }

    public function clientes()
    {
        return $this->hasMany(ClientesMapa::class, 'mapa_id');
    }

    public function filial()
    {
        return $this->belongsTo(Filial::class, 'filial_id');
    }
}
