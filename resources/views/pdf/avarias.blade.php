<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Relatório de Avarias - Cobeb</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 13px;
            color: #334155;
            margin: 0;
            padding: 0;
        }

        /* CABEÇALHO COM LOGO */
        .header-table {
            width: 100%;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .header-table td {
            vertical-align: middle;
            border: none;
            /* Remove a borda padrão da tabela */
        }

        .logo-container {
            width: 150px;
            text-align: left;
        }

        .logo-container img {
            max-width: 100%;
            height: auto;
            max-height: 80px;
            /* Limita a altura da logo */
        }

        .company-info {
            text-align: right;
        }

        .company-info h1 {
            margin: 0;
            color: #1e3a8a;
            font-size: 22px;
            text-transform: uppercase;
        }

        .company-info p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 12px;
        }

        /* DADOS DO CLIENTE */
        .info-box-full {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px;
            background-color: #f8fafc;
            margin-bottom: 25px;
        }

        .info-box-full h2 {
            font-size: 14px;
            color: #1e3a8a;
            margin-top: 0;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .info-box-full p {
            margin: 6px 0;
            font-size: 13px;
        }

        /* SEÇÃO DE CADA NOTA FISCAL */
        .nota-section {
            border-top: 2px dashed #cbd5e1;
            padding-top: 15px;
            margin-top: 20px;
            page-break-inside: avoid;
            /* Evita que a nota quebre no meio da página */
        }

        .nota-header {
            background-color: #e2e8f0;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 13px;
            color: #1e3a8a;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        /* TABELA DE PRODUTOS */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }

        table th,
        table td {
            border: 1px solid #cbd5e1;
            padding: 8px;
            text-align: left;
        }

        table th {
            background-color: #1e3a8a;
            color: #ffffff;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11px;
        }

        table tr:nth-child(even) {
            background-color: #f1f5f9;
        }

        /* ASSINATURA E RODAPÉ */
        .signature-area {
            margin-top: 60px;
            text-align: center;
            page-break-inside: avoid;
        }

        .signature-line {
            width: 300px;
            border-top: 1px solid #475569;
            margin: 0 auto 10px auto;
        }

        .signature-area p {
            margin: 2px 0;
            font-size: 13px;
            font-weight: bold;
            color: #334155;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }
    </style>
</head>

<body>

    <!-- Cabeçalho com Logomarca usando Tabela (Mais seguro para PDF) -->
    <table class="header-table">
        <tr>
            <td class="logo-container">
                <!-- IMPORTANTE: Ajuste o caminho abaixo para onde sua logo está salva na pasta public -->
                <img src="{{ public_path('assets/cobeb-logo-dark.png') }}" alt="Cobeb Logo">
            </td>
            <td class="company-info">
                <p>Pará de Minas/MG | Relatório de Troca / Produtos Avariados</p>
                <p style="margin-top: 8px;">
                    <strong>Protocolo:</strong> {{ $protocolo }} |
                    <strong>Data:</strong> {{ \Carbon\Carbon::now()->timezone('America/Sao_Paulo')->format('d/m/Y') }}
                </p>
            </td>
        </tr>
    </table>

    <!-- Dados do Cliente (Aparece apenas 1 vez) -->
    <div class="info-box-full">
        <h2>Dados do Cliente</h2>
        <p><strong>Nome/Razão Social:</strong> {{ $cliente->nome }}</p>
        <p><strong>{{ isset($cliente->cpf) ? 'CPF' : 'CNPJ' }}:</strong> {{ $cliente->cpf ?? $cliente->cnpj ?? 'Não informado' }}</p>
        <p><strong>Endereço:</strong> {{ $cliente->endereco }}</p>
        <p><strong>Telefone:</strong> {{ $cliente->telefone }}</p>
    </div>

    <!-- Laço de repetição para cada Avaria (Nota Fiscal) -->
    @foreach($avarias as $avaria)
    @php
    // Pega os dados da Nota através do primeiro item avariado
    $primeiroItem = $avaria->itens->first();
    $notaFiscal = $primeiroItem ? $primeiroItem->produtoNotaFiscal->notaFiscal : null;
    @endphp

    <div class="nota-section">
        <div class="nota-header">
            DADOS DA NOTA -
            NF: {{ $notaFiscal->numero ?? 'N/A' }} |
            Pedido: {{ $notaFiscal->pedido ?? 'N/A' }} |
            Emissão: {{ $notaFiscal && $notaFiscal->data_emissao ? \Carbon\Carbon::parse($notaFiscal->data_emissao)->format('d/m/Y') : 'N/A' }}
        </div>

        <!-- Tabela de Produtos desta Avaria -->
        <table>
            <thead>
                <tr>
                    <th width="15%">Código</th>
                    <th width="45%">Descrição do Produto</th>
                    <th width="15%">Qtd.</th>
                    <th width="25%">Motivo da Avaria</th>
                </tr>
            </thead>
            <tbody>
                @forelse($avaria->itens as $item)
                <tr>
                    <td>{{ $item->produtoNotaFiscal->produto->codigo ?? 'N/A' }}</td>
                    <td>{{ $item->produtoNotaFiscal->produto->descricao ?? 'N/A' }}</td>
                    <td>{{ $item->quantidade_avariada }}</td>
                    <td>{{ $item->tipoAvaria->descricao ?? 'N/A' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" style="text-align: center; padding: 15px;">Nenhum produto registrado para esta nota.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endforeach

    <!-- Assinatura -->
    <div class="signature-area">
        <div class="signature-line"></div>
        <p>Responsável pela Expedição</p>
        <p style="font-weight: normal; color: #64748b; font-size: 12px;">Cobeb - Pará de Minas/MG</p>
    </div>

    <!-- Rodapé -->
    <div class="footer">
        Documento gerado eletronicamente pelo sistema em {{ \Carbon\Carbon::now()->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') }}.
    </div>

</body>

</html>
