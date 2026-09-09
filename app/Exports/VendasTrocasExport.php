<?php

namespace App\Exports;

use App\Models\Cliente;
use App\Models\Produto;
use App\Models\NotaFiscal;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class VendasTrocasExport implements FromArray, WithHeadings, WithEvents, WithStyles, WithColumnWidths
{

    public function headings(): array
    {
        /**
         * Nota, Nr. Pedido, Cliente, Dt. Operação, Operação, Emissão, Produto, Qtde, Desconto, Adic. Finan, Total
         */
        return [
            'Cliente',
            'Operação',
            'Dt. Operação',
            'Emissão',
            'Nota',
            'Produto',
            'Qtde',
            'Adic. Finan',
            'Desconto',
            'Total',
            'Nr. Pedido'
        ];
    }

    public function array(): array
    {

        return [
            [
                '12345',
                '5',
                '01/01/2026',
                '01/01/2026',
                '123456',
                '1234',
                '10',
                '0,00',
                '0,00',
                '100,00',
                '123456'
            ]
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Estiliza a Linha 1 (Cabeçalho)
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => Color::COLOR_WHITE],
                    'size' => 11,
                    'width' => 100,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => '1F4E78'], // Azul escuro
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $mainSheet = $event->sheet->getDelegate();
                $spreadsheet = $mainSheet->getParent();
                $lists = $spreadsheet->createSheet()->setTitle('Clientes_Produtos');
                $notes = $spreadsheet->createSheet()->setTitle('Notas_Cliente');
                $items = $spreadsheet->createSheet()->setTitle('Produtos_Nota');
                foreach ([$lists, $notes, $items] as $sheet) {
                    $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
                }

                // Texto explícito preserva códigos e evita interpretar dados como fórmulas.
                $write = static function (Worksheet $sheet, string $cell, $value): void {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                };
                $name = static function (string $name, Worksheet $sheet, string $range) use ($spreadsheet): void {
                    $spreadsheet->addNamedRange(new NamedRange($name, $sheet, $range));
                };
                $clientes = Cliente::orderBy('codigo')->pluck('codigo');
                $produtos = Produto::orderBy('codigo')->pluck('codigo');
                foreach ($clientes as $index => $codigo) {
                    $write($lists, 'A' . ($index + 1), $codigo);
                }
                foreach ($produtos as $index => $codigo) {
                    $write($lists, 'B' . ($index + 1), $codigo);
                }
                foreach (['1', '5', '39'] as $index => $codigo) {
                    $write($lists, 'C' . ($index + 1), $codigo);
                }
                $name('ListaClientes', $lists, '$A$1:$A$' . max(1, $clientes->count()));
                $name('ListaProdutos', $lists, '$B$1:$B$' . max(1, $produtos->count()));
                $name('ListaOperacoes', $lists, '$C$1:$C$3');
                $name('ListaVazia', $lists, '$D$1');

                // Listas verticais: não há uma coluna por cliente/nota nem limite de 16 mil grupos.
                // A:B mapeia a chave ao nome do intervalo; C contém os valores da lista.
                $noteRow = 1;
                $itemRow = 1;
                $clientMapRow = 1;
                $noteMapRow = 1;
                $clientId = null;
                $clientCode = null;
                $clientStart = 1;
                $finishClient = function () use (&$clientMapRow, &$clientCode, &$clientStart, &$noteRow, $write, $name, $notes): void {
                    if ($clientCode === null) {
                        return;
                    }
                    $rangeName = 'NotasGrupo_' . $clientMapRow;
                    $write($notes, 'A' . $clientMapRow, $clientCode);
                    $write($notes, 'B' . $clientMapRow, $rangeName);
                    $name($rangeName, $notes, '$C$' . $clientStart . ':$C$' . ($noteRow - 1));
                    $clientMapRow++;
                };

                $invoices = NotaFiscal::query()
                    ->with(['cliente:id,codigo', 'produtos.produto:id,codigo'])
                    ->whereHas('cliente')
                    ->orderBy('cliente_id')->orderBy('numero')->orderBy('id');
                foreach ($invoices->lazy(500) as $invoice) {
                    if ($clientId !== $invoice->cliente_id) {
                        $finishClient();
                        $clientId = $invoice->cliente_id;
                        $clientCode = (string) $invoice->cliente->codigo;
                        $clientStart = $noteRow;
                    }
                    $write($notes, 'C' . $noteRow++, $invoice->numero);
                    $start = $itemRow;
                    foreach ($invoice->produtos->pluck('produto.codigo')->filter(fn ($code) => $code !== null)->unique() as $code) {
                        $write($items, 'C' . $itemRow++, $code);
                    }
                    $rangeName = 'ListaVazia';
                    if ($itemRow > $start) {
                        $rangeName = 'ProdutosGrupo_' . $noteMapRow;
                        $name($rangeName, $items, '$C$' . $start . ':$C$' . ($itemRow - 1));
                    }
                    $write($items, 'A' . $noteMapRow, $clientCode . '|' . $invoice->numero);
                    $write($items, 'B' . $noteMapRow++, $rangeName);
                }
                $finishClient();
                $name('MapaNotas', $notes, '$A$1:$B$' . max(1, $clientMapRow - 1));
                $name('MapaProdutos', $items, '$A$1:$B$' . max(1, $noteMapRow - 1));

                $validation = static function (string $formula, string $prompt, bool $strict = true): DataValidation {
                    $rule = new DataValidation();
                    $rule->setType(DataValidation::TYPE_LIST);
                    $rule->setErrorStyle(DataValidation::STYLE_STOP);
                    $rule->setAllowBlank(true);
                    $rule->setShowDropDown(true);
                    $rule->setShowInputMessage(true);
                    $rule->setPromptTitle('Seleção assistida');
                    $rule->setPrompt($prompt);
                    $rule->setShowErrorMessage($strict);
                    $rule->setErrorTitle('Valor fora da lista');
                    $rule->setError('Selecione um valor disponível para os dados desta linha.');
                    $rule->setFormula1($formula);
                    return $rule;
                };
                $mainSheet->setDataValidation('A2:A10000', $validation('ListaClientes', 'Selecione o cliente. Ao alterá-lo, selecione novamente a nota e o produto.'));
                $mainSheet->setDataValidation('B2:B10000', $validation('ListaOperacoes', '1: entrada; 5 e 39: troca.'));
                // Referências de linha relativas acompanham cada linha, inclusive ao colar/copiar.
                $mainSheet->setDataValidation('E2:E10000', $validation(
                    'INDIRECT(IFERROR(VLOOKUP($A2&"",MapaNotas,2,FALSE),"ListaVazia"))',
                    'Selecione uma nota do cliente. Para entrada (operação 1), pode digitar uma nota nova. Ao alterar a nota, selecione novamente o produto.',
                    false
                ));
                $mainSheet->setDataValidation('F2:F10000', $validation(
                    'INDIRECT(IF($B2&""="1","ListaProdutos",IFERROR(VLOOKUP($A2&"|"&$E2,MapaProdutos,2,FALSE),"ListaVazia")))',
                    'Troca: selecione cliente e nota para listar seus produtos. Entrada: selecione um produto cadastrado.'
                ));
            },
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15, // Cliente
            'B' => 15, // Operação
            'C' => 15, // Dt. Operação
            'D' => 15, // Emissão
            'E' => 15, // Nota
            'F' => 20, // Produto
            'G' => 10, // Qtde
            'H' => 15, // Adic. Finan
            'I' => 15, // Desconto
            'J' => 15, // Total
            'K' => 20, // Nr. Pedido
        ];
    }
}
