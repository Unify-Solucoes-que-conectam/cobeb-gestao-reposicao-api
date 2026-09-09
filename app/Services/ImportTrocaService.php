<?php

namespace App\Services;

use App\Models\Avaria;
use App\Models\Cliente;
use App\Models\ItemAvaria;
use App\Models\NotaFiscal;
use App\Models\ProdutoNotaFiscal;
use App\Models\Troca;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $item = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$item) throw new \RuntimeException('O produto não pertence à nota fiscal informada.');
        if ((int) $item->operacao !== 1) {
            throw new \RuntimeException('O item da nota não é uma entrada (operação 1). Confira a entrada original antes de registrar a troca.');
        }
        $damages = Avaria::where('cliente_id', $client->id)->whereIn('status', ['aprovada', 'trocada'])
            ->whereHas('itens', fn ($q) => $q->where('produto_nota_fiscal_id', $item->id))->orderBy('id');
        $damageIds = ($lock ? $damages->lockForUpdate() : $damages)->pluck('id');
        $damageItems = ItemAvaria::whereIn('avaria_id', $damageIds)->where('produto_nota_fiscal_id', $item->id)->orderBy('id');
        $trades = Troca::where('produto_nota_fiscal_id', $item->id)->orderBy('id');
        // Leituras bloqueadas também precisam enxergar commits feitos enquanto aguardávamos o bloqueio.
        $approved = (int) ($lock ? $damageItems->lockForUpdate() : $damageItems)->get()->sum('quantidade_avariada');
        return ['item' => $item, 'approved' => $approved, 'trades' => ($lock ? $trades->lockForUpdate() : $trades)->get()];
    }

    private function decision(array $row, array $options, array $snapshot, int $delta = 0): array
    {
        $date = $this->date((string) ($row['dt_operacao'] ?? ''));
        $operation = trim((string) $row['operacao']);
        $matches = $snapshot['trades']->filter(fn ($t) => $t->operacao === $operation && (string) $t->data_operacao === $date);
        if ($matches->count() > 1) throw new \RuntimeException('Há trocas históricas duplicadas para este movimento. Concilie os registros antes de continuar.');
        $existing = $matches->first();
        $base = ['troca_id' => $existing?->id, 'correcoes' => (int) ($existing?->correcoes ?? 0), 'data' => $date,
            'item_id' => $snapshot['item']->id, 'operacao' => $operation];
        if ($existing && ($options['duplicateAction'] ?? 'ignore') === 'ignore') {
            return $base + ['status' => 'ignored', 'message' => 'Troca existente: será ignorada sem nova mensagem.'];
        }
        $raw = trim((string) ($row['quantidade'] ?? ''));
        if (!preg_match('/^\d+$/', $raw) || (float) $raw < 1 || (float) $raw > 2147483647) {
            throw new \RuntimeException('A quantidade deve ser um número inteiro positivo.');
        }
        $quantity = (int) $raw;
        $reason = trim((string) ($row['motivo_parcial'] ?? ''));
        if (mb_strlen($reason) > 1000) throw new \RuntimeException('O motivo deve ter no máximo 1000 caracteres.');
        if ($existing && (int) $existing->quantidade === $quantity && trim((string) $existing->motivo_parcial) === $reason) {
            return $base + ['status' => 'ignored', 'message' => 'Dados idênticos: nenhuma correção ou nova mensagem.'];
        }
        $other = (int) $snapshot['trades']->sum('quantidade') - (int) ($existing?->quantidade ?? 0) + $delta;
        $available = max(0, (int) $snapshot['item']->quantidade - $other);
        $approved = max(0, $snapshot['approved'] - $other);
        $result = $base + ['status' => 'valid', 'message' => $existing ? 'A troca será corrigida e o cliente receberá um novo aviso.' : 'Nova troca.',
            'disponivel' => $available, 'aprovada' => $approved, 'quantidade' => $quantity, 'motivo_parcial' => $reason,
            'parcial' => $quantity < $approved, 'delta' => $quantity - (int) ($existing?->quantidade ?? 0)];
        if ($quantity > $available) {
            return array_merge($result, ['status' => 'error', 'message' => "Quantidade solicitada ({$quantity}) maior que a disponível na nota ({$available})."]);
        }
        if ($quantity > $approved) {
            return array_merge($result, ['status' => 'error', 'message' => "Quantidade solicitada ({$quantity}) maior que o saldo de avarias aprovadas ({$approved})."]);
        }
        if ($quantity < $approved && $reason === '') {
            return array_merge($result, ['status' => 'error', 'message' => "Há {$approved} unidades aprovadas e serão enviadas {$quantity}. Informe o motivo do envio parcial; ele será mostrado ao cliente."]);
        }
        if ($existing && $result['correcoes'] >= 2) {
            // A confirmação só vale para esta versão, quantidade e justificativa.
            $scope = [$existing->id, $result['correcoes'], (int) $existing->quantidade, (string) $existing->motivo_parcial, $quantity, $reason];
            $confirmed = false;
            try {
                $confirmed = json_decode(Crypt::decryptString((string) ($row['confirmacao_correcao'] ?? '')), true) === $scope;
            } catch (\Throwable $e) {
            }
            if (!$confirmed) {
                return array_merge($result, ['status' => 'confirmation', 'message' => "Esta troca já foi corrigida {$result['correcoes']} vezes. Deseja corrigir novamente? Um novo WhatsApp será enviado e poderá gerar custo.",
                    'confirmation_token' => Crypt::encryptString(json_encode($scope))]);
            }
        }
        return $result;
    }

    public function preview(array $records, array $options): array
    {
        $results = $cache = $deltas = $seen = [];
        foreach ($records as $index => $row) {
            if (!self::isTroca($row)) continue;
            $result = ['row_index' => $index];
            try {
                $key = json_encode([$row['cliente'] ?? '', $row['nota_fiscal'] ?? '', $row['produto'] ?? '']);
                $snapshot = $cache[$key] ??= $this->snapshot($row, false);
                $movement = json_encode([$snapshot['item']->id, $row['operacao'], $this->date((string) ($row['dt_operacao'] ?? ''))]);
                if (isset($seen[$movement])) throw new \RuntimeException('Movimento repetido no arquivo. Corrija ou desmarque a repetição.');
                $seen[$movement] = true;
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

    public function save(array $row, array $options, ?string $userId): array
    {
        return DB::transaction(function () use ($row, $options, $userId) {
            $snapshot = $this->snapshot($row, true);
            $result = $this->decision($row, $options, $snapshot);
            if ($result['status'] === 'ignored') return $result;
            if ($result['status'] !== 'valid') throw new \RuntimeException($result['message']);
            $trade = $result['troca_id'] ? $snapshot['trades']->firstWhere('id', $result['troca_id']) : new Troca();
            if ($trade->exists) {
                DB::table('troca_correcoes')->insert([
                    'id' => (string) Str::uuid(), 'troca_id' => $trade->id, 'numero' => $result['correcoes'] + 1,
                    'quantidade_anterior' => $trade->quantidade, 'quantidade_nova' => $result['quantidade'],
                    'motivo_anterior' => $trade->motivo_parcial, 'motivo_novo' => $result['motivo_parcial'],
                    'confirmacao_extra' => $result['correcoes'] >= 2, 'usuario_id' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $trade->correcoes = $result['correcoes'] + 1;
            }
            $trade->fill(['produto_nota_fiscal_id' => $snapshot['item']->id, 'operacao' => $result['operacao'],
                'data_operacao' => $result['data'], 'quantidade' => $result['quantidade'], 'motivo_parcial' => $result['motivo_parcial'] ?: null]);
            $trade->save();
            return $result + ['item' => $snapshot['item'], 'trade' => $trade];
        }, 3);
    }
}
