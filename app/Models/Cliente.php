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

    /**
     * O Cód PDV é único por filial (revenda), então o mesmo código pode existir na LP e na PM.
     * Resolve o cliente usando, nesta ordem: a filial informada, o único cliente com o código
     * ou o dono da nota fiscal. Lança exceção quando não for possível decidir.
     */
    public static function resolverPorCodigo(string $codigo, ?string $filialCodigo = null, ?string $notaNumero = null): ?self
    {
        if (blank($codigo)) {
            return null;
        }

        if (filled($filialCodigo)) {
            $filialId = Filial::query()->where('codigo', $filialCodigo)->value('id');
            if (!$filialId) {
                throw new \RuntimeException("Filial '{$filialCodigo}' não encontrada.");
            }

            return self::query()->where('codigo', $codigo)->where('filial_id', $filialId)->first();
        }

        $candidatos = self::query()->where('codigo', $codigo)->get();
        if ($candidatos->count() <= 1) {
            return $candidatos->first();
        }

        if (filled($notaNumero)) {
            // a nota pode repetir entre filiais: só decide se apenas um dos candidatos tem essa nota
            $donos = NotaFiscal::query()->where('numero', $notaNumero)
                ->whereIn('cliente_id', $candidatos->pluck('id'))->distinct()->pluck('cliente_id');
            if ($donos->count() === 1) {
                return $candidatos->firstWhere('id', $donos->first());
            }
        }

        throw new \RuntimeException("O cliente {$codigo} existe em mais de uma filial. Informe a coluna Filial.");
    }

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

    public function contatos()
    {
        return $this->hasMany(ClienteTelefones::class, 'cliente_id');
    }
}
