<?php

namespace App\Imports;

use App\Events\ImportProgressUpdated;
use App\Models\Avaria;
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
use App\Models\Usuario;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class GenericImport implements ToCollection, WithChunkReading, WithHeadingRow, WithCustomCsvSettings
{
    private string $batchId;
    private string $type;
    private int $totalRows;
    private int $processedRows = 0;
    private int $errorCount = 0;

    // Array para cache em memória das Foreign Keys e evitar N+1 queries
    private array $fkCache = [];

    // trocas processadas e que serão retornadas para o controller
    private array $trocas = [];

    public function __construct(string $batchId, string $type, int $totalRows)
    {
        $this->batchId = $batchId;
        $this->type = $type;
        $this->totalRows = $totalRows;
    }

    /**
     * Processa um chunk inteiro de uma vez (por padrão 500 linhas)
     */
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $data = $row->toArray();

            try {
                $this->importRow($data);
                $this->processedRows++;
            } catch (\Throwable $exception) {
                $this->processedRows++;
                $this->errorCount++;

                Log::warning('Import row failed', [
                    'batch_id' => $this->batchId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Atualiza o progresso no banco e dispara o WebSocket apenas UMA VEZ no final do chunk
        $this->updateProgress();
    }

    private function importRow(array $data): void
    {
        match ($this->type) {
            'clientes' => $this->importCliente($data),
            'motoristas' => $this->importMotorista($data),
            'produtos' => $this->importProduto($data),
            'mapas' => $this->importMapa($data),
            'vendas_trocas' => $this->importVendaTroca($data),
            default => throw new \RuntimeException("Tipo de importação desconhecido: {$this->type}"),
        };
    }

    // ─── Clientes ───────────────────────────────────────────────────────

    private function importCliente(array $data): void
    {
        // Com o WithHeadingRow, o cabeçalho original é formatado para slug
        $codigo = Arr::get($data, 'cod_pdv');
        $documento = trim((string) Arr::get($data, 'documento'));
        $nomeFantasia = trim((string) Arr::get($data, 'nome_fantasia'));
        $razaoSocial = trim((string) Arr::get($data, 'razao_social'));
        $endereco = trim((string) Arr::get($data, 'endereco'));
        $complemento = trim((string) Arr::get($data, 'complemento'));
        $bairro = trim((string) Arr::get($data, 'bairro'));
        $cidade = trim((string) Arr::get($data, 'cidade'));
        $uf = trim((string) Arr::get($data, 'uf'));
        $cep = trim((string) Arr::get($data, 'cep'));
        $filial = trim((string) Arr::get($data, 'filial'));
        $descCategoria = trim((string) Arr::get($data, 'categoria'));
        $tipoPessoa = preg_replace('/[^a-zA-Z0-9]/', '', trim((string) Arr::get($data, 'tipo_de_pessoa')));
        $status = trim((string) Str::lower(Arr::get($data, 'status_do_pdv')));

        if (blank($codigo) || blank($nomeFantasia)) {
            throw new \RuntimeException('Faltando Cód PDV ou Nome Fantasia');
        }

        /**
         * Como o arquivo de importação contém apenas a descrição da categoria cadastramos apenas a descriçao e guardamos o id
         * para referenciar na tabela de clientes. Se a categoria já existir, apenas pegamos o id dela.
         */
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
                'documento' => $documento,
                'nome_fantasia' => $nomeFantasia,
                'razao_social' => $razaoSocial,
                'endereco' => $endereco,
                'complemento' => $complemento,
                'bairro' => $bairro,
                'cidade' => $cidade,
                'uf' => $uf,
                'cep' => $cep,
                'latitude' => null,
                'longitude' => null,
                'filial_id' => $this->resolveFk(Filial::class, 'codigo', $filial),
                'categoria_id' => $categoriaId,
                'tipo_pessoa' => $tipoPessoa, // remover caracteres especiais
                'status' => $status,
            ]
        );

        /**
         * O campo telefone_s é um campo de texto que contém várias telefones separados por "|", os telefones estão em formatos distintos
         * xx xxxx-xxxx, xx xxxxx-xxxx
         */
        $telefonesRaw = (string) Arr::get($data, 'telefones');

        // Se o campo de telefone estiver totalmente vazio, ignora o processo
        if (blank($telefonesRaw)) {
            return;
        }

        // Explode pela barra "|" e limpa espaços das pontas e caracteres invisíveis
        $telefonesArray = array_map(function ($tel) {
            // Remove espaços extras nas pontas e normaliza espaços invisíveis de planilha
            return trim(preg_replace('/\s+/', ' ', $tel));
        }, explode('|', $telefonesRaw));

        // Remove itens vazios e REINDEXA o array (crucial para o $telefonesArray[0] funcionar)
        $telefonesArray = array_values(array_unique(array_filter($telefonesArray)));

        $totalTelefones = count($telefonesArray);

        if ($totalTelefones === 0) {
            return;
        }

        // Se houver apenas 1 telefone, ele é o WhatsApp. Se houver mais, o 1º é o principal/WhatsApp
        foreach ($telefonesArray as $index => $telefone) {
            // Define isWhatsapp como true apenas para o único ou o primeiro telefone do registro
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
        $codigo = trim((string) Arr::get($data, 'codigo'));
        $ean = trim((string) Arr::get($data, 'ean'));
        $descricao = trim((string) Arr::get($data, 'descricao'));
        $precoUnitario = 0;

        $tipoMarca = trim((string) Arr::get($data, 'tipo_marca'));
        $codTipoMarca = $tipoMarca; // Inicialmente assume que o código é o mesmo que a descrição
        if (str_contains($tipoMarca, ' - ')) {
            [$codTipoMarca, $tipoMarca] = explode(' - ', $tipoMarca, 2);
            $codTipoMarca = trim($codTipoMarca);
            $tipoMarca = trim($tipoMarca);
        }

        $codEmbalagem = trim((string) Arr::get($data, 'embalagem'));
        $embalagem = trim((string) Arr::get($data, 'embalagem'));
        if (str_contains($embalagem, ' - ')) {
            [$codEmbalagem, $embalagem] = explode(' - ', $embalagem, 2);
            $codEmbalagem = trim($codEmbalagem);
            $embalagem = trim($embalagem);
        }

        if (blank($codigo)) {
            throw new \RuntimeException('Missing codigo');
        }

        /**
         * Cadastrar tipo de marca automaticamente caso não exista.
         */
        $tipoMarcaId = $this->resolveFk(TipoMarca::class, 'codigo', $codTipoMarca);
        if (!$tipoMarcaId && !blank($tipoMarca)) {
            $tipoMarca = TipoMarca::updateOrCreate(
                ['codigo' => $codTipoMarca ?? $tipoMarca],
                [
                    'descricao' => $tipoMarca,
                ]
            );
            $tipoMarcaId = $tipoMarca->id;
        }

        /**
         * Cadastrar embalagem automaticamente caso não exista.
         */
        $embalagemId = $this->resolveFk(Embalagem::class, 'codigo', $codEmbalagem);
        if (!$embalagemId && !blank($embalagem)) {
            $embalagem = Embalagem::updateOrCreate(
                ['codigo' => $codEmbalagem ?? $embalagem],
                [
                    'descricao' => $embalagem,
                ]
            );
            $embalagemId = $embalagem->id;
        }

        Produto::updateOrCreate(
            ['codigo' => $codigo],
            [
                'ean' => $ean,
                'descricao' => $descricao,
                'preco_unitario' => $this->toDecimal($precoUnitario),
                'tipo_marca_id' => $tipoMarcaId,
                'embalagem_id' => $embalagemId,
            ]
        );
    }

    // ─── Motoristas ─────────────────────────────────────────────────────

    private function importMotorista(array $data): void
    {
        $cpf = trim((string) Arr::get($data, 'cpf'));
        $codigo = trim((string) Arr::get($data, 'codmotorista'));
        $nome = trim((string) Arr::get($data, 'nome_motorista'));
        $cod_cluster = trim((string) Arr::get($data, 'codcluster'));
        $desc_cluster = trim((string) Arr::get($data, 'cluster'));
        $cod_filial = trim((string) Arr::get($data, 'codfilial'));
        $data_admissao = trim((string) Arr::get($data, 'data_admissao'));
        $data_inativacao = trim((string) Arr::get($data, 'data_inativacao'));

        if (blank($codigo) || blank($nome)) {
            throw new \RuntimeException('Missing codigo or nome');
        }

        /**
         * Cadastrar cluster automaticamente caso não exista.
         */
        $cluster = Cluster::updateOrCreate(
            ['codigo' => $cod_cluster],
            [
                'descricao' => $desc_cluster,
            ]
        );

        /**
         * Cadastrar um usuário automaticamente para cada motorista.
         * O usuário será criado com a senha igual ao CPF (deve ser alterada posteriormente).
         */
        $usuario = Usuario::updateOrCreate(
            ['cpf' => $cpf],
            [
                'nome' => $nome,
                'senha' => $cpf,
                'role' => 'motorista',
                'primeiro_acesso' => true,
            ]
        );

        Motorista::updateOrCreate(
            ['codigo' => $codigo],
            [
                'filial_id' => $this->resolveFk(Filial::class, 'codigo', $cod_filial),
                'cluster_id' => $cluster->id,
                'usuario_id' => $usuario->id,
                'status' => Str::lower(Arr::get($data, 'status')),
                'data_admissao' => $this->toDate($data_admissao),
                'data_inativacao' => $this->toDate($data_inativacao),
            ]
        );
    }

    // ─── Mapas ─────────────────────────────────────────────────────
    private function importMapa(array $data): void
    {
        $codigo = trim((string) Arr::get($data, 'nro_do_mapa'));
        $codMotorista = trim((string) Arr::get($data, 'motorista'));
        $dataEntrega = trim((string) Arr::get($data, 'data_entrega'));
        $placa = trim((string) Arr::get($data, 'placa'));
        $clientes = trim((string) Arr::get($data, 'clientes'));

        if (blank($codigo)) {
            throw new \RuntimeException('Missing codigo (nro_do_mapa)');
        }

        // 1. Guarda a instância do Mapa criado/atualizado
        $mapa = Mapa::updateOrCreate(
            ['codigo' => $codigo],
            [
                'motorista_id' => $this->resolveFk(Motorista::class, 'codigo', $codMotorista),
                'data_entrega' => $this->toDate($dataEntrega),
                'placa'        => $placa,
            ]
        );

        // 2. Cadastrar clientes do mapa
        if (!blank($clientes)) {
            // 'strlen' garante que só remova elementos vazios
            $clientesArray = array_filter(array_map('trim', explode('/', $clientes)), 'strlen');

            foreach ($clientesArray as $codCliente) {
                // Tenta resolver a FK do cliente
                $clienteId = $this->resolveFk(Cliente::class, 'codigo', ltrim($codCliente, '0'));

                if ($clienteId) {
                    ClientesMapa::updateOrCreate([
                        'mapa_id'    => $mapa->id, // Usando o ID direto do mapa recém criado
                        'cliente_id' => $clienteId,
                    ]);
                } else {
                    // Log para você saber exatamente qual cliente não foi encontrado no banco
                    Log::warning("[ImportMapa] Cliente '{$codCliente}' não encontrado no banco para o Mapa '{$codigo}'.");
                }
            }
        }
    }

    // ─── Vendas e Trocas ─────────────────────────────────────────────────────

    private function importVendaTroca(array $data)
    {
        $numero = trim((string) Arr::get($data, 'nota'));
        $pedido = trim((string) Arr::get($data, 'nr_pedido'));
        $codCliente = trim((string) Arr::get($data, 'cliente'));
        $dataOperacao = trim((string) Arr::get($data, 'dt_operacao'));
        $operacao = trim((string) Arr::get($data, 'operacao'));
        $data_emissao = trim((string) Arr::get($data, 'emissao'));

        $produto = trim((string) Arr::get($data, 'produto'));
        $quantidade = (int) Arr::get($data, 'qtde');
        $valorDesconto = $this->toDecimal(Arr::get($data, 'desconto'));
        $valorAdicional = $this->toDecimal(Arr::get($data, 'adic_finan'));
        $valorTotal = $this->toDecimal(Arr::get($data, 'total'));

        if (blank($numero)) {
            throw new \RuntimeException('Missing numero');
        }

        /**
         * Cadastrar notas fiscais
         */
        NotaFiscal::updateOrCreate(
            ['numero' => $numero],
            [
                'pedido' => $pedido,
                'cliente_id' => $this->resolveFk(Cliente::class, 'codigo', $codCliente),
                'operacao' => $operacao,
                'data_operacao' => $this->toDate($dataOperacao),
                'data_emissao' => $this->toDate($data_emissao),
            ]
        );

        /**
         * Como cada nota fiscal pode ter vários produtos, cadastramos cada produto da nota fiscal na tabela produtos_nota_fiscal
         * Cada linha da tabela possui o código da nota fiscal, o código do produto e seus detalhes,
         * como quantidade, valor de desconto, valor adicional e valor total.
         */
        ProdutoNotaFiscal::updateOrCreate(
            [
                'nota_fiscal_id' => $this->resolveFk(NotaFiscal::class, 'numero', $numero),
                'produto_id' => $this->resolveFk(Produto::class, 'codigo', $produto),
            ],
            [
                'quantidade' => $quantidade,
                'valor_desconto' => $valorDesconto,
                'valor_adicional' => $valorAdicional,
                'valor_total' => $valorTotal,
            ]
        );

        $cliente = Cliente::query()->where('codigo', $codCliente)->first();


        if ($cliente) {

            // 1. Cria uma chave única para agrupar as trocas por Cliente e Data
            $chaveAgrupamento = $cliente->id . '_' . $dataOperacao;

            // 2. Verifica se este cliente/data já foi processado nesta execução
            if (!isset($this->trocas[$chaveAgrupamento])) {

                // Monta a query base única buscando no model Avaria
                $avarias = Avaria::query()
                    ->where('cliente_id', $cliente->id)
                    ->where('status', 'aprovada')
                    ->whereDate('data_aprovacao', $this->toDate($dataOperacao))

                    // 1. Filtra QUAIS Avarias serão retornadas (apenas as que têm essa nota e operação)
                    ->whereHas('itens.produtoNotaFiscal.notaFiscal', function ($query) use ($numero) {
                        $query->where('numero', $numero)
                            ->whereIn('operacao', ['5', '39']);
                    })

                    // 2. Filtra os DADOS carregados dentro dessas Avarias (Constraining Eager Loads)
                    ->with([
                        'itens' => function ($query) use ($numero) {
                            // Aplica o mesmo filtro nos itens que serão carregados na memória
                            $query->whereHas('produtoNotaFiscal.notaFiscal', function ($subQuery) use ($numero) {
                                $subQuery->where('numero', $numero)
                                    ->whereIn('operacao', ['5', '39']);
                            })
                                // Carrega as sub-relações apenas para os itens que passaram no filtro
                                ->with(['produtoNotaFiscal.notaFiscal', 'tipoAvaria']);
                        }
                    ])
                    ->get();

                Log::info("[ImportVendaTroca] Cliente '{$cliente->codigo}' - Avarias encontradas: " . $avarias->count() . " para a nota '{$numero}'.");

                // Busca o telefone do cliente com a flag isWhatsapp
                $contatoCliente = ClienteTelefones::query()
                    ->where('cliente_id', $cliente->id)
                    ->where('isWhatsapp', true)
                    ->first();

                // 3. Se houver avarias, armazena no array usando a chave de agrupamento
                if ($avarias->isNotEmpty()) {
                    $this->trocas[$chaveAgrupamento] = [
                        'cliente' => $cliente,
                        'avarias' => $avarias,
                        'data_operacao' => $this->toDate($dataOperacao),
                        'contatoCliente' => $contatoCliente,
                        'protocolo' => 'TRC-' . $dataOperacao . '-' . $cliente->codigo,
                    ];
                }
            }
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Busca o relacionamento no banco. Possui cache interno para evitar
     * que a mesma query seja repetida mil vezes.
     */
    private function resolveFk(string $modelClass, string $column, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $cacheKey = $modelClass . '_' . $column;

        // Se o valor já foi buscado no banco antes, retorna do cache em memória
        if (isset($this->fkCache[$cacheKey][$value])) {
            return $this->fkCache[$cacheKey][$value];
        }

        $record = $modelClass::query()->where($column, $value)->first();

        // Salva o resultado no cache (mesmo se for null, para não ficar re-buscando registro inexistente)
        $this->fkCache[$cacheKey][$value] = $record?->id;

        return $this->fkCache[$cacheKey][$value];
    }

    private function updateProgress(): void
    {
        $percentage = $this->totalRows > 0 ? (int) floor(($this->processedRows / $this->totalRows) * 100) : 0;
        $percentage = min($percentage, 100); // Impede que passe de 100%

        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch) {
            $batch->update([
                'processed_rows' => $this->processedRows,
                'percentage' => $percentage,
                'last_log' => "Importing rows in progress",
                'current_step' => 'processing',
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

        // 1. Se já for um objeto DateTime/Carbon
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // 2. Se for Número Serial do Excel (ex: 46230) -> Converte matematicamente
        if (is_numeric($value)) {
            try {
                $timestamp = ($value - 25569) * 86400;
                return \Carbon\Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d');
            } catch (\Throwable $e) {
                // Se não for um número de data válido, ignora e tenta os passos abaixo
            }
        }

        $valueStr = trim((string) $value);

        // 3. Se for string no formato brasileiro (ex: "27/07/2026")
        if (str_contains($valueStr, '/')) {
            try {
                return \Carbon\Carbon::createFromFormat('d/m/Y', $valueStr)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        // 4. Se for texto em formato ISO (ex: "2026-07-27")
        try {
            return \Carbon\Carbon::parse($valueStr)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
        ];
    }

    // retornar as trocas processadas para o controller
    public function getTrocas(): array
    {
        return $this->trocas;
    }
}
