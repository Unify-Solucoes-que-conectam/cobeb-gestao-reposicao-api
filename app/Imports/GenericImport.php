<?php

namespace App\Imports;

use App\Events\ImportProgressUpdated;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ClientesMapa;
use App\Models\ClienteTelefones;
use App\Models\Cluster;
use App\Models\Embalagem;
use App\Models\Filial;
use App\Models\ImportBatch;
use App\Models\Mapa;
use App\Models\Motorista;
use App\Models\NotaFiscal;
use App\Models\Produto;
use App\Models\ProdutoNotaFiscal;
use App\Models\TipoMarca;
use App\Models\Troca;
use App\Models\Usuario;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class GenericImport
{
    private string $batchId;
    private string $type;
    private int $totalRows;
    private int $processedRows = 0;
    private int $errorCount = 0;

    private array $fkCache = [];
    private array $trocas = [];

    public function __construct(string $batchId, string $type, int $totalRows)
    {
        $this->batchId = $batchId;
        $this->type = $type;
        $this->totalRows = $totalRows;
    }

    public function processRecords(array $records): void
    {
        foreach (array_chunk($records, 100) as $chunk) {
            $chunkProcessed = 0;

            foreach ($chunk as $data) {
                try {
                    $this->importRow($data);
                } catch (\Throwable $exception) {
                    $this->errorCount++;

                    Log::warning('Import row failed', [
                        'batch_id' => $this->batchId,
                        'error'    => $exception->getMessage(),
                    ]);
                }
                $chunkProcessed++;
                $this->processedRows++;
            }

            $this->updateProgress($chunkProcessed);
        }
    }

    private function importRow(array $data): void
    {
        match ($this->type) {
            'clientes'      => $this->importCliente($data),
            'motoristas'    => $this->importMotorista($data),
            'produtos'      => $this->importProduto($data),
            'mapas'         => $this->importMapa($data),
            'vendas_trocas' => $this->importVendaTroca($data),
            default         => throw new \RuntimeException("Tipo de importação desconhecido: {$this->type}"),
        };
    }

    // ─── Clientes ───────────────────────────────────────────────────────

    private function importCliente(array $data): void
    {
        $codigo       = Arr::get($data, 'cod_pdv');
        $documento    = trim((string) Arr::get($data, 'documento'));
        $nomeFantasia = trim((string) Arr::get($data, 'nome_fantasia'));
        $razaoSocial  = trim((string) Arr::get($data, 'razao_social'));
        $endereco     = trim((string) Arr::get($data, 'endereco'));
        $complemento  = trim((string) Arr::get($data, 'complemento'));
        $bairro       = trim((string) Arr::get($data, 'bairro'));
        $cidade       = trim((string) Arr::get($data, 'cidade'));
        $uf           = trim((string) Arr::get($data, 'uf'));
        $cep          = trim((string) Arr::get($data, 'cep'));
        $filial       = trim((string) Arr::get($data, 'filial'));
        $descCategoria = trim((string) Arr::get($data, 'categoria'));
        $tipoPessoa   = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) Arr::get($data, 'tipo_de_pessoa')));
        $status       = trim((string) Str::lower(Arr::get($data, 'status_do_pdv')));

        if (blank($codigo) || blank($nomeFantasia)) {
            throw new \RuntimeException('Faltando Cód PDV ou Nome Fantasia');
        }

        $categoriaId = null;

        if (!blank($descCategoria)) {
            $cacheKey = 'categoria_' . Str::slug($descCategoria);

            if (!isset($this->fkCache[$cacheKey])) {
                $categoria = Categoria::firstOrCreate([
                    'descricao' => $descCategoria
                ]);
                $this->fkCache[$cacheKey] = $categoria->id;
            }
            $categoriaId = $this->fkCache[$cacheKey];
        }

        $cliente = Cliente::updateOrCreate(
            ['codigo' => $codigo],
            [
                'documento'     => $documento,
                'nome_fantasia' => $nomeFantasia,
                'razao_social'  => $razaoSocial,
                'endereco'      => $endereco,
                'complemento'   => $complemento,
                'bairro'        => $bairro,
                'cidade'        => $cidade,
                'uf'            => $uf,
                'cep'           => $cep,
                'latitude'      => null,
                'longitude'     => null,
                'filial_id'     => $this->resolveFk(Filial::class, 'codigo', $filial),
                'categoria_id'  => $categoriaId,
                'tipo_pessoa'   => $tipoPessoa,
                'status'        => $status,
            ]
        );

        $telefonesRaw = (string) Arr::get($data, 'telefones');

        if (blank($telefonesRaw)) {
            return;
        }

        $telefonesArray = array_map(function ($tel) {
            return trim(preg_replace('/\s+/', ' ', $tel));
        }, explode('|', $telefonesRaw));

        $telefonesArray = array_values(array_unique(array_filter($telefonesArray)));
        $totalTelefones = count($telefonesArray);

        if ($totalTelefones === 0) {
            return;
        }

        foreach ($telefonesArray as $index => $telefone) {
            $isWhatsapp = ($totalTelefones === 1) || ($index === 0);
            $telefoneLimpo = preg_replace('/[^0-9]/', '', $telefone);

            ClienteTelefones::updateOrCreate(
                [
                    'cliente_id' => $cliente->id,
                    'numero'     => $telefoneLimpo
                ],
                [
                    'isWhatsapp' => $isWhatsapp
                ]
            );
        }
    }

    // ─── Produtos ───────────────────────────────────────────────────────

    private function importProduto(array $data): void
    {
        $codigo        = trim((string) Arr::get($data, 'codigo'));
        $ean           = trim((string) Arr::get($data, 'ean'));
        $descricao     = trim((string) Arr::get($data, 'descricao'));
        $precoUnitario = 0;

        $tipoMarca    = trim((string) Arr::get($data, 'tipo_marca'));
        $codTipoMarca = $tipoMarca;
        if (str_contains($tipoMarca, ' - ')) {
            [$codTipoMarca, $tipoMarca] = explode(' - ', $tipoMarca, 2);
            $codTipoMarca = trim($codTipoMarca);
            $tipoMarca    = trim($tipoMarca);
        }

        $codEmbalagem = trim((string) Arr::get($data, 'embalagem'));
        $embalagem    = trim((string) Arr::get($data, 'embalagem'));
        if (str_contains($embalagem, ' - ')) {
            [$codEmbalagem, $embalagem] = explode(' - ', $embalagem, 2);
            $codEmbalagem = trim($codEmbalagem);
            $embalagem    = trim($embalagem);
        }

        if (blank($codigo)) {
            throw new \RuntimeException('Missing codigo');
        }

        $tipoMarcaId = $this->resolveFk(TipoMarca::class, 'codigo', $codTipoMarca);
        if (!$tipoMarcaId && !blank($tipoMarca)) {
            $tipoMarcaRecord = TipoMarca::updateOrCreate(
                ['codigo' => $codTipoMarca ?? $tipoMarca],
                ['descricao' => $tipoMarca]
            );
            $tipoMarcaId = $tipoMarcaRecord->id;
        }

        $embalagemId = $this->resolveFk(Embalagem::class, 'codigo', $codEmbalagem);
        if (!$embalagemId && !blank($embalagem)) {
            $embalagemRecord = Embalagem::updateOrCreate(
                ['codigo' => $codEmbalagem ?? $embalagem],
                ['descricao' => $embalagem]
            );
            $embalagemId = $embalagemRecord->id;
        }

        Produto::updateOrCreate(
            ['codigo' => $codigo],
            [
                'ean'            => $ean,
                'descricao'      => $descricao,
                'preco_unitario' => $this->toDecimal($precoUnitario),
                'tipo_marca_id'  => $tipoMarcaId,
                'embalagem_id'   => $embalagemId,
            ]
        );
    }

    // ─── Motoristas ─────────────────────────────────────────────────────

    private function importMotorista(array $data): void
    {
        $cpf             = $this->normalizeCPF(trim((string) Arr::get($data, 'cpf')));
        $codigo          = trim((string) Arr::get($data, 'codmotorista'));
        $nome            = trim((string) Arr::get($data, 'nome_motorista'));
        $cod_cluster     = trim((string) Arr::get($data, 'codcluster'));
        $desc_cluster    = trim((string) Arr::get($data, 'cluster'));
        $cod_filial      = trim((string) Arr::get($data, 'codfilial'));
        $data_admissao   = trim((string) Arr::get($data, 'data_admissao'));
        $data_inativacao = trim((string) Arr::get($data, 'data_inativacao'));
        $status          = trim((string) Arr::get($data, 'status'));

        if (blank($codigo) || blank($nome)) {
            throw new \RuntimeException('Missing codigo or nome');
        }

        $cluster = Cluster::updateOrCreate(
            ['codigo' => $cod_cluster],
            ['descricao' => $desc_cluster]
        );

        $usuario = Usuario::updateOrCreate(
            ['cpf' => $cpf],
            [
                'nome'            => $nome,
                'senha'           => $cpf,
                'role'            => 'motorista',
                'primeiro_acesso' => true,
            ]
        );

        Log::info($cpf);

        Motorista::updateOrCreate(
            ['codigo' => $codigo],
            [
                'filial_id'       => $this->resolveFk(Filial::class, 'codigo', $cod_filial),
                'cluster_id'      => $cluster->id,
                'usuario_id'      => $usuario->id,
                'status'          => Str::lower(Arr::get($data, 'status')),
                'data_admissao'   => $this->toDate($data_admissao),
                'data_inativacao' => $this->toDate($data_inativacao),
            ]
        );
    }

    // ─── Mapas ──────────────────────────────────────────────────────────

    private function importMapa(array $data): void
    {
        $codigo       = trim((string) Arr::get($data, 'nro_do_mapa'));
        $codFilial    = trim((string) Arr::get($data, 'unb'));
        $codMotorista = trim((string) Arr::get($data, 'motorista'));
        $dataEntrega  = trim((string) Arr::get($data, 'data_entrega'));
        $placa        = trim((string) Arr::get($data, 'placa'));
        $clientes     = trim((string) Arr::get($data, 'clientes'));

        if (blank($codigo)) {
            throw new \RuntimeException('Missing codigo (nro_do_mapa)');
        }

        if (blank($codFilial)) {
            throw new \RuntimeException('Missing codFilial (unb)');
        }

        $mapa = Mapa::updateOrCreate(
            ['codigo' => $codigo],
            [
                'filial_id'    => $this->resolveFk(Filial::class, 'codigo', $codFilial),
                'motorista_id' => $this->resolveFk(Motorista::class, 'codigo', $codMotorista),
                'data_entrega' => $this->toDate($dataEntrega),
                'placa'        => $placa,
            ]
        );

        if (!blank($clientes)) {
            $clientesArray = array_filter(array_map('trim', explode('/', $clientes)), 'strlen');

            foreach ($clientesArray as $codCliente) {
                $clienteId = $this->resolveFk(Cliente::class, 'codigo', ltrim($codCliente, '0'));

                if ($clienteId) {
                    ClientesMapa::updateOrCreate([
                        'mapa_id'    => $mapa->id,
                        'cliente_id' => $clienteId,
                    ]);
                } else {
                    Log::warning("[ImportMapa] Cliente '{$codCliente}' não encontrado no banco para o Mapa '{$codigo}'.");
                }
            }
        }
    }

    // ─── Vendas e Trocas ─────────────────────────────────────────────────

    private function importVendaTroca(array $data): void
    {
        $numero       = trim((string) Arr::get($data, 'nota'));
        $pedido       = trim((string) Arr::get($data, 'nr_pedido'));
        $codCliente   = trim((string) Arr::get($data, 'cliente'));
        $dataOperacao = trim((string) Arr::get($data, 'dt_operacao'));
        $operacao     = trim((string) Arr::get($data, 'operacao'));
        $data_emissao = trim((string) Arr::get($data, 'emissao'));

        $produto        = trim((string) Arr::get($data, 'produto'));
        $quantidade     = (int) Arr::get($data, 'qtde');
        $valorDesconto  = $this->toDecimal(Arr::get($data, 'desconto'));
        $valorAdicional = $this->toDecimal(Arr::get($data, 'adic_finan'));
        $valorTotal     = $this->toDecimal(Arr::get($data, 'total'));

        if (blank($numero)) {
            throw new \RuntimeException('Missing numero');
        }

        $clienteId = $this->resolveFk(Cliente::class, 'codigo', $codCliente);

        $notaFiscal = NotaFiscal::updateOrCreate(
            ['numero' => $numero],
            [
                'pedido'       => $pedido,
                'cliente_id'   => $clienteId,
                'data_emissao' => $this->toDate($data_emissao),
            ]
        );

        $produtoId = $this->resolveFk(Produto::class, 'codigo', $produto);

        if (!$produtoId) {
            throw new \RuntimeException("Produto com código '{$produto}' não foi encontrado no banco de dados.");
        }

        $produtoNotaFiscal = ProdutoNotaFiscal::updateOrCreate(
            [
                'nota_fiscal_id' => $notaFiscal->id,
                'produto_id'     => $produtoId,
            ],
            [
                'quantidade'      => $quantidade,
                'valor_desconto'  => $valorDesconto,
                'valor_adicional' => $valorAdicional,
                'valor_total'     => $valorTotal,
                'operacao'        => $operacao,
                'data_operacao'   => $this->toDate($dataOperacao),
            ]
        );

        if (!in_array((int) $operacao, [5, 39], true) && !in_array($operacao, ['5', '39'], true)) {
            return;
        }

        Log::info($this->toDate($dataOperacao));

        Troca::updateOrCreate(
            [
                'produto_nota_fiscal_id' => $produtoNotaFiscal->id,
                'operacao'               => $operacao,
                'data_operacao'          => $this->toDate($dataOperacao),
            ],
            [
                'quantidade' => $quantidade,
            ]
        );

        // CORREÇÃO: Busca segura da relação com contatos sem quebrar quando $clienteId é null
        $cliente = $clienteId ? Cliente::with('contatos')->find($clienteId) : null;

        if (!$cliente) {
            return;
        }

        $produtoNotaFiscal->load(['produto', 'notaFiscal']);

        if (!isset($this->trocas[$cliente->id])) {
            $cliente->nome = $cliente->razao_social ?: $cliente->nome_fantasia;

            $doc = preg_replace('/[^0-9]/', '', (string) $cliente->documento);
            if (strlen($doc) === 11) {
                $cliente->cpf = $cliente->documento;
            } elseif (strlen($doc) === 14) {
                $cliente->cnpj = $cliente->documento;
            }

            $telWhats = $cliente->contatos()->where('isWhatsapp', true)->first()?->numero;
            $telPadrao = $cliente->contatos()->first()?->numero;
            $cliente->numero = $telWhats ?? $telPadrao ?? 'Não informado';

            $this->trocas[$cliente->id] = [
                'cliente'        => (object) [
                    'nome'     => $cliente->nome,
                    'cpf'      => $cliente->cpf ?? null,
                    'cnpj'     => $cliente->cnpj ?? null,
                    'telefone' => $cliente->numero,
                    'endereco' => $cliente->endereco . ($cliente->complemento ? ' - ' . $cliente->complemento : '') . ', ' . $cliente->bairro . ', ' . $cliente->cidade . '/' . $cliente->uf . ' - CEP: ' . $cliente->cep,
                ],
                'protocolo'      => 'TRC-' . $notaFiscal->numero,
                'contatoCliente' => $cliente->numero,
                'data_operacao'  => $this->toDate($dataOperacao),
                'notas'          => [],
            ];
        }

        if (!isset($this->trocas[$cliente->id]['notas'][$numero])) {
            $this->trocas[$cliente->id]['notas'][$numero] = [
                'itens' => collect([]),
            ];
        }

        $itemAvaria = (object) [
            'produtoNotaFiscal'   => $produtoNotaFiscal,
            'quantidade_avariada' => $quantidade,
            'tipoAvaria'          => (object) [
                'descricao' => "Troca - Operação {$operacao}"
            ],
        ];

        $this->trocas[$cliente->id]['notas'][$numero]['itens']->push($itemAvaria);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function resolveFk(string $modelClass, string $column, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $cacheKey = $modelClass . '_' . $column;

        if (isset($this->fkCache[$cacheKey][$value])) {
            return $this->fkCache[$cacheKey][$value];
        }

        $record = $modelClass::query()->where($column, $value)->first();

        $this->fkCache[$cacheKey][$value] = $record?->id;

        return $this->fkCache[$cacheKey][$value];
    }

    private function updateProgress(int $chunkProcessedRows): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch) {
            // Incrementa as linhas processadas de forma acumulativa
            $batch->increment('processed_rows', $chunkProcessedRows);
            $batch->refresh();

            $percentage = $batch->total_rows > 0
                ? (int) min(floor(($batch->processed_rows / $batch->total_rows) * 100), 100)
                : 100;

            $batch->update([
                'percentage'   => $percentage,
                'last_log'     => "Importing rows in progress",
                'current_step' => $percentage >= 100 ? 'completed' : 'processing',
            ]);

            event(new ImportProgressUpdated($batch));
        }
    }

    private function toDecimal(mixed $value): ?float
    {
        if (blank($value)) return null;
        $v = str_replace(',', '.', trim((string) $value));
        return is_numeric($v) ? (float) $v : null;
    }

    private function toDate($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                $timestamp = ($value - 25569) * 86400;
                return \Carbon\Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        $valueStr = trim((string) $value);

        if (str_contains($valueStr, '/')) {
            try {
                return \Carbon\Carbon::createFromFormat('d/m/Y', $valueStr)->format('Y-m-d');
            } catch (\Throwable $e) {
                // Continue to other formats
            }
        }

        // Tenta parsear formatos comuns de data
        $formats = [
            'Y-m-d H:i:s',
            'd/m/Y H:i:s',
            'm/d/Y H:i:s',
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'D M d Y H:i:s', // Thu Aug 20 2026 00:00:28
        ];

        foreach ($formats as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, $valueStr)->format('Y-m-d');
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Tenta parsear com strtotime (mais flexível)
        try {
            // Remove timezone info se houver (ex: GMT-0300 (Horário Padrão de Brasília))
            $cleanStr = preg_replace('/\s+GMT[\+\-]\d{4}\s*\([^)]*\)/', '', $valueStr);
            $timestamp = strtotime($cleanStr);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        } catch (\Throwable $e) {
        }

        // Último recurso: tenta Carbon::parse()
        try {
            return \Carbon\Carbon::parse($valueStr)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeCPF(string $cpf): string
    {
        if (blank($cpf)) {
            return '';
        }

        $cpfLimpo = preg_replace('/[^0-9]/', '', $cpf);

        if (strlen($cpfLimpo) > 11) {
            throw new \RuntimeException("CPF inválido: contém mais de 11 dígitos ('{$cpf}')");
        }

        if (strlen($cpfLimpo) < 11) {
            $cpfLimpo = str_pad($cpfLimpo, 11, '0', STR_PAD_LEFT);
        }

        return $cpfLimpo;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getTrocas(): array
    {
        $trocasFormatadas = [];

        foreach ($this->trocas as $clienteId => $dadosCliente) {
            $avarias = collect($dadosCliente['notas'])->map(function ($nota) {
                return (object) [
                    'itens' => $nota['itens'],
                ];
            })->values();

            $trocasFormatadas[$clienteId] = [
                'cliente'        => $dadosCliente['cliente'],
                'protocolo'      => $dadosCliente['protocolo'],
                'contatoCliente' => $dadosCliente['contatoCliente'],
                'data_operacao'  => $dadosCliente['data_operacao'],
                'avarias'        => $avarias,
            ];
        }

        return $trocasFormatadas;
    }
}
