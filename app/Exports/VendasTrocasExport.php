<?php

namespace App\Exports;

use App\Models\Cliente;
use App\Models\Produto;
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
        $clientes = Cliente::pluck('codigo')->toArray();
        $produtos = Produto::pluck('codigo')->toArray();
        $operacoes = ['1', '5', '39'];

        return [
            AfterSheet::class => function (AfterSheet $event) use ($clientes, $produtos, $operacoes) {
                $spreadsheet = $event->sheet->getDelegate()->getParent();

                // 1. Cria uma aba auxiliar para armazenar as listas
                $listSheet = $spreadsheet->createSheet();
                $listSheet->setTitle('Clientes_Produtos');

                // Oculta a aba para o usuário final não ver
                $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                // 2. Preenche a coluna A da aba auxiliar com os Clientes
                foreach ($clientes as $index => $codigo) {
                    $row = $index + 1;
                    $listSheet->setCellValue("A{$row}", $codigo);
                }
                $totalClientes = count($clientes);

                // 3. Preenche a coluna B da aba auxiliar com os Produtos
                foreach ($produtos as $index => $codigo) {
                    $row = $index + 1;
                    $listSheet->setCellValue("B{$row}", $codigo);
                }
                $totalProdutos = count($produtos);

                // 4. Preenche a coluna C da aba auxiliar com as Operações
                foreach ($operacoes as $index => $codigo) {
                    $row = $index + 1;
                    $listSheet->setCellValue("C{$row}", $codigo);
                }
                $totalOperacoes = count($operacoes);

                // 4. Aplica as validações apontando para o intervalo de células
                $mainSheet = $event->sheet->getDelegate();

                if ($totalClientes > 0) {
                    $validationA = new DataValidation();
                    $validationA->setType(DataValidation::TYPE_LIST);
                    $validationA->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validationA->setAllowBlank(true);
                    $validationA->setShowDropDown(true);
                    // Aponta para a coluna A da aba oculta
                    $validationA->setFormula1("Clientes_Produtos!\$A\$1:\$A\${$totalClientes}");
                    $mainSheet->setDataValidation("A2:A10000", $validationA);
                }

                if ($totalProdutos > 0) {
                    $validationE = new DataValidation();
                    $validationE->setType(DataValidation::TYPE_LIST);
                    $validationE->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validationE->setAllowBlank(true);
                    $validationE->setShowDropDown(true);
                    // Aponta para a coluna B da aba oculta
                    $validationE->setFormula1("Clientes_Produtos!\$B\$1:\$B\${$totalProdutos}");
                    $mainSheet->setDataValidation("F2:F10000", $validationE);
                }

                if ($totalOperacoes > 0) {
                    $validationB = new DataValidation();
                    $validationB->setType(DataValidation::TYPE_LIST);
                    $validationB->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validationB->setAllowBlank(true);
                    $validationB->setShowDropDown(true);
                    // Aponta para a coluna C da aba oculta
                    $validationB->setFormula1("Clientes_Produtos!\$C\$1:\$C\${$totalOperacoes}");
                    $mainSheet->setDataValidation("B2:B10000", $validationB);
                }
            }
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
