<?php

namespace App\Support;

/**
 * Unidades de medida aceitas no importador de Vendas e Trocas.
 * Mantenha sincronizado com UNIDADES_MEDIDA no front-end (src/pages/admin/importacoes/config.ts).
 */
class UnidadeMedida
{
    public const OPCOES = ['UN', 'CX', 'DZ', 'FD', 'PCT', 'GF', 'LT', 'KG'];

    public static function normalize(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /** Retorna a unidade normalizada ou lança exceção se for inválida. */
    public static function validate(mixed $value): string
    {
        $unidade = self::normalize($value);
        if ($unidade === '') {
            throw new \RuntimeException('Informe a unidade de medida (' . implode(', ', self::OPCOES) . ').');
        }
        if (! in_array($unidade, self::OPCOES, true)) {
            throw new \RuntimeException("Unidade de medida \"{$unidade}\" inválida. Use: " . implode(', ', self::OPCOES) . '.');
        }

        return $unidade;
    }
}
