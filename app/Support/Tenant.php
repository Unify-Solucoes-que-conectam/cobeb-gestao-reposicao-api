<?php

namespace App\Support;

use App\Models\Filial;
use App\Models\Usuario;

/**
 * Tenant (filial) em que o usuário está conectado na requisição atual.
 *
 * - "geral": acesso a todas as filiais; cada registro informa a sua filial.
 * - filial: tudo o que o usuário cria/importa pertence a esta filial.
 */
final class Tenant
{
    public const GERAL = 'geral';

    private function __construct(
        public readonly bool $geral,
        public readonly ?Filial $filial,
    ) {}

    public static function geral(): self
    {
        return new self(true, null);
    }

    public static function filial(Filial $filial): self
    {
        return new self(false, $filial);
    }

    public static function nenhum(): self
    {
        return new self(false, null);
    }

    /** Tenant da requisição atual (definido pelo middleware ResolveTenant). */
    public static function atual(): self
    {
        return app()->bound(self::class) ? app(self::class) : self::nenhum();
    }

    public function id(): ?string
    {
        return $this->geral ? self::GERAL : $this->filial?->id;
    }

    public function definido(): bool
    {
        return $this->geral || $this->filial !== null;
    }

    /**
     * Restringe a consulta à filial do tenant.
     *
     * - Geral: aplica o filtro de filial escolhido na tela (se houver).
     * - Filial: sempre a filial conectada; o filtro da tela é ignorado.
     * - Sem tenant: nenhum registro.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $query
     */
    public function restringir($query, string $coluna, ?string $filtroGeral = null)
    {
        if ($this->geral) {
            return filled($filtroGeral) ? $query->where($coluna, $filtroGeral) : $query;
        }

        if ($this->filial) {
            return $query->where($coluna, $this->filial->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Resolve o tenant solicitado (header X-Tenant) validando o acesso do usuário.
     * Sem solicitação, usa o padrão: Geral se permitido, senão a primeira filial liberada.
     * Retorna null quando o usuário não tem acesso ao tenant solicitado.
     */
    public static function resolver(Usuario $usuario, ?string $solicitado): ?self
    {
        $solicitado = filled($solicitado) ? trim($solicitado) : null;

        if ($solicitado === self::GERAL) {
            return $usuario->temAcessoGeral() ? self::geral() : null;
        }

        if ($solicitado !== null) {
            $filial = $usuario->filiaisPermitidas()->firstWhere('id', $solicitado);
            return $filial ? self::filial($filial) : null;
        }

        if ($usuario->temAcessoGeral()) {
            return self::geral();
        }

        $primeira = $usuario->filiaisPermitidas()->first();
        return $primeira ? self::filial($primeira) : self::nenhum();
    }

    /** Opções de tenant exibidas no seletor do front. */
    public static function opcoes(Usuario $usuario): array
    {
        $opcoes = [];

        if ($usuario->temAcessoGeral()) {
            $opcoes[] = ['id' => self::GERAL, 'nome' => 'Geral', 'codigo' => null, 'geral' => true];
        }

        foreach ($usuario->filiaisPermitidas() as $filial) {
            $opcoes[] = ['id' => $filial->id, 'nome' => $filial->descricao, 'codigo' => $filial->codigo, 'geral' => false];
        }

        return $opcoes;
    }
}
