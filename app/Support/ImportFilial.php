<?php

namespace App\Support;

/**
 * Injeta a filial do tenant conectado nos registros importados.
 *
 * - Tenant "Geral": nada muda; cada registro informa a sua filial.
 * - Tenant de uma filial: registros sem filial recebem a filial conectada e
 *   registros de outra filial são recusados (evita importar o arquivo errado).
 */
final class ImportFilial
{
    /** Coluna que guarda o código da filial em cada tipo de importação. */
    public const COLUNAS = [
        'clientes' => 'filial',
        'motoristas' => 'codfilial',
        'mapas' => 'unb',
        'vendas_trocas' => 'filial',
    ];

    /**
     * @return array{0: array, 1: string[]} registros ajustados e erros por linha
     */
    public static function aplicar(string $tipo, array $registros, Tenant $tenant): array
    {
        $coluna = self::COLUNAS[$tipo] ?? null;

        if ($tenant->geral || $coluna === null || $tenant->filial === null) {
            return [$registros, []];
        }

        $codigo = (string) $tenant->filial->codigo;
        $erros = [];

        foreach ($registros as $indice => $registro) {
            $informado = trim((string) ($registro[$coluna] ?? ''));

            if ($informado === '') {
                $registros[$indice][$coluna] = $codigo;
                continue;
            }

            if (!self::mesmaFilial($informado, $codigo)) {
                $erros[] = 'Registro ' . ($indice + 1) . ": filial '{$informado}' diferente da filial conectada ({$tenant->filial->descricao}).";
            }
            else {
                $registros[$indice][$coluna] = $codigo;
            }
        }

        return [$registros, $erros];
    }

    /** Compara códigos ignorando zeros à esquerda (ex.: "01" e "1"). */
    private static function mesmaFilial(string $a, string $b): bool
    {
        $normalizar = fn (string $v) => ctype_digit($v) ? (ltrim($v, '0') ?: '0') : mb_strtolower($v);

        return $normalizar($a) === $normalizar($b);
    }
}
