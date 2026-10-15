<?php

namespace App\Services;

use App\Models\Avaria;
use App\Models\Cliente;
use App\Models\ItemAvaria;
use App\Models\NotaFiscal;
use App\Models\ProdutoNotaFiscal;
use App\Models\Produto;
use App\Models\Troca;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ImportTrocaService
{
    public static function isTroca(array $row): bool
    {
        return in_array(trim((string) ($row['operacao'] ?? '')), ['5', '39'], true);
    }

    private function date(string $value): string
    {
        $value = trim($value);

        // Remove trechos entre parênteses como "(Horário Padrão de Brasília)"
        $cleanValue = preg_replace('/\s*\([^)]*\)/', '', $value);

        try {
            // 1. Data vinda do Excel (Número serial)
            if (is_numeric($cleanValue)) {
                return Carbon::createFromTimestamp(((float) $cleanValue - 25569) * 86400, 'UTC')->format('Y-m-d');
            }

            // 2. Data em Formato Brasileiro (d/m/Y)
            if (str_contains($cleanValue, '/')) {
                $date = Carbon::createFromFormat('!d/m/Y', explode(' ', $cleanValue)[0]);
                if ($date) {
                    return $date->format('Y-m-d');
                }
            }

            // 3. Converte datas no formato JS (ex: "Wed Sep 09 2026 00:00:28 GMT-0300"), ISO 8601, etc.
            return Carbon::parse($cleanValue)->format('Y-m-d');
        } catch (\Throwable $e) {
            // Falha no parse
        }

        throw new \RuntimeException('Informe uma data de operação válida.');
    }

    private function snapshot(array $row, bool $lock): array
    {
        $client = Cliente::where('codigo', trim((string) ($row['cliente'] ?? '')))->first();
        if (!$client) throw new \RuntimeException('Cliente não encontrado.');
        $query = NotaFiscal::where('numero', trim((string) ($row['nota_fiscal'] ?? '')));
        $note = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$note) throw new \RuntimeException('Nota fiscal não encontrada. Importe a entrada primeiro.');
        if ($note->cliente_id !== $client->id) {
            throw new \RuntimeException("A nota {$note->numero} não pertence ao cliente {$client->codigo} informado.");
        }
        $query = ProdutoNotaFiscal::where('nota_fiscal_id', $note->id)
            ->whereHas('produto', fn ($q) => $q->where('codigo', trim((string) ($row['produto'] ?? ''))));
        $items = ($lock ? $query->lockForUpdate() : $query)->orderBy('created_at')->orderBy('id')->get();
        if ($items->isEmpty()) throw new \RuntimeException('O produto não pertence à nota fiscal informada.');
        if ($items->contains(fn ($item) => !in_array((string) $item->operacao, ['1', '2'], true))) {
            throw new \RuntimeException('O item da nota não é uma entrada ou bonificação (operação 1 ou 2). Confira a origem antes de registrar a troca.');
        }
        $item = $items->first();
        $itemIds = $items->pluck('id');
        $damages = Avaria::where('cliente_id', $client->id)->whereIn('status', ['aprovada', 'trocada'])
            ->whereHas('itens', fn ($q) => $q->whereIn('produto_nota_fiscal_id', $itemIds))->orderBy('id');
        $damageIds = ($lock ? $damages->lockForUpdate() : $damages)->pluck('id');
        $damageItems = ItemAvaria::whereIn('avaria_id', $damageIds)->whereIn('produto_nota_fiscal_id', $itemIds)->orderBy('id');
        $trades = Troca::whereIn('produto_nota_fiscal_id', $itemIds)->orderBy('id');
        // Leituras bloqueadas também precisam enxergar commits feitos enquanto aguardávamos o bloqueio.
        $approved = (int) ($lock ? $damageItems->lockForUpdate() : $damageItems)->get()->sum('quantidade_avariada');
        return ['item' => $item, 'items' => $items, 'approved' => $approved,
            'quantity' => (int) $items->sum('quantidade'), 'trades' => ($lock ? $trades->lockForUpdate() : $trades)->get()];
    }

    private function decision(array $row, array $options, array $snapshot, int $delta = 0): array
    {
        $date = $this->date((string) ($row['dt_operacao'] ?? ''));
        $operation = trim((string) $row['operacao']);
        $base = ['troca_id' => null, 'correcoes' => 0, 'data' => $date,
            'item_id' => $snapshot['item']->id, 'operacao' => $operation];
        $raw = trim((string) ($row['quantidade'] ?? ''));
        if (!preg_match('/^\d+$/', $raw) || (float) $raw < 1 || (float) $raw > 2147483647) {
            throw new \RuntimeException('A quantidade deve ser um número inteiro positivo.');
        }
        $quantity = (int) $raw;
        $reason = trim((string) ($row['motivo_parcial'] ?? ''));
        if (mb_strlen($reason) > 1000) throw new \RuntimeException('O motivo deve ter no máximo 1000 caracteres.');
        $other = (int) $snapshot['trades']->sum('quantidade') + $delta;
        $available = max(0, $snapshot['quantity'] - $other);
        $approved = max(0, $snapshot['approved'] - $other);
        $result = $base + ['status' => 'valid', 'message' => 'Nova troca.',
            'disponivel' => $available, 'aprovada' => $approved, 'quantidade' => $quantity, 'motivo_parcial' => $reason,
            'parcial' => $quantity < $approved, 'delta' => $quantity];
        if ($quantity > $available) {
            return array_merge($result, ['status' => 'error', 'message' => "Quantidade solicitada ({$quantity}) maior que a disponível na nota ({$available})."]);
        }
        if ($quantity > $approved) {
            return array_merge($result, ['status' => 'error', 'message' => "Quantidade solicitada ({$quantity}) maior que o saldo de avarias aprovadas ({$approved})."]);
        }
        if ($quantity < $approved && $reason === '') {
            return array_merge($result, ['status' => 'error', 'message' => "Há {$approved} unidades aprovadas e serão enviadas {$quantity}. Informe o motivo do envio parcial; ele será mostrado ao cliente."]);
        }
        return $result;
    }

    public function preview(array $records, array $options): array
    {
        $results = $cache = $deltas = [];
        foreach ($records as $index => $row) {
            if (!self::isTroca($row)) continue;
            $result = ['row_index' => $index];
            try {
                $key = json_encode([$row['cliente'] ?? '', $row['nota_fiscal'] ?? '', $row['produto'] ?? '']);
                $snapshot = $cache[$key] ??= $this->snapshot($row, false);
                $decision = $this->decision($row, $options, $snapshot, $deltas[$snapshot['item']->id] ?? 0);
                $result += $decision;
                if (in_array($decision['status'], ['valid', 'confirmation'], true)) {
                    $deltas[$snapshot['item']->id] = ($deltas[$snapshot['item']->id] ?? 0) + $decision['delta'];
                }
            } catch (\RuntimeException $e) {
                $result += ['status' => 'error', 'message' => $e->getMessage()];
            }
            $results[] = $result;
        }
        return $results;
    }

    /** Valida todas as linhas da rotina antes de a importação ser enfileirada. */
    public function validateRecords(array $records, array $options): array
    {
        $results = $cache = $deltas = [];
        foreach ($records as $index => $row) {
            $result = ['row_index' => $index];
            try {
                $operation = trim((string) ($row['operacao'] ?? ''));
                if (!in_array($operation, ['1', '2', '5', '39'], true)) {
                    throw new \RuntimeException('Operação inválida. Use 1, 2, 5 ou 39.');
                }
                $clientCode = trim((string) ($row['cliente'] ?? ''));
                $productCode = trim((string) ($row['produto'] ?? ''));
                if (!Cliente::where('codigo', $clientCode)->exists()) {
                    throw new \RuntimeException('Cliente não encontrado.');
                }
                if (!Produto::where('codigo', $productCode)->exists()) {
                    throw new \RuntimeException('Produto não encontrado.');
                }
                if (trim((string) ($row['nota_fiscal'] ?? '')) === '') {
                    throw new \RuntimeException('Informe uma nota fiscal válida.');
                }
                $raw = trim((string) ($row['quantidade'] ?? ''));
                if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
                    throw new \RuntimeException('A quantidade deve ser um número inteiro positivo.');
                }
                $this->date((string) ($row['dt_operacao'] ?? ''));

                if (!self::isTroca($row)) {
                    $result += ['status' => 'valid', 'message' => 'Entrada válida.'];
                } else {
                    $key = json_encode([$clientCode, $row['nota_fiscal'], $productCode]);
                    $snapshot = $cache[$key] ??= $this->snapshot($row, false);
                    $decision = $this->decision($row, $options, $snapshot, $deltas[$snapshot['item']->id] ?? 0);
                    $result += $decision;
                    if ($decision['status'] === 'valid') {
                        $deltas[$snapshot['item']->id] = ($deltas[$snapshot['item']->id] ?? 0) + $decision['delta'];
                    }
                }
            } catch (\RuntimeException $e) {
                $result += ['status' => 'error', 'message' => $e->getMessage()];
            }
            $results[] = $result;
        }
        return $results;
    }

    public function save(array $row, array $options, ?string $userId): array
    {
        return DB::transaction(function () use ($row, $options, $userId) {
            $snapshot = $this->snapshot($row, true);
            $result = $this->decision($row, $options, $snapshot);
            if ($result['status'] === 'ignored') return $result;
            if ($result['status'] !== 'valid') throw new \RuntimeException($result['message']);
            $trade = new Troca();
            $trade->usuario_responsavel_id = $userId;
            $trade->fill(['produto_nota_fiscal_id' => $snapshot['item']->id, 'operacao' => $result['operacao'],
                'data_operacao' => $result['data'], 'quantidade' => $result['quantidade'], 'motivo_parcial' => $result['motivo_parcial'] ?: null]);
            $trade->save();
            app(TrocaAvariaAllocationService::class)->rebuild($snapshot['item']->id);
            return $result + ['item' => $snapshot['item'], 'trade' => $trade];
        }, 3);
    }
}
