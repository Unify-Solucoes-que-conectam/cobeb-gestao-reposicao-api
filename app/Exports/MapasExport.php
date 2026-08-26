<?php

namespace App\Exports;

use App\Models\Filial;
use App\Models\Motorista;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class MapasExport implements FromArray, WithHeadings, WithEvents, WithStyles, WithColumnWidths, WithCustomValueBinder
{
    public function headings(): array
    {
        return [
            'UNB',
            'Data Entrega',
            'Nro do Mapa',
            'Placa',
            'Motorista',
            'Clientes'
        ];
    }

    public function array(): array
    {
        return [
            [
                '1',
                '01/01/2026',
                '1',
                'ABC1234',
                '123',
                '12345/54321/13542/12453'
            ]
        ];
    }

    public function bindValue(Cell $cell, $value)
    {
        // Força as colunas B (Data) e F (Clientes com barras) a serem tratadas como TEXTO
        if (in_array($cell->getColumn(), ['B', 'F'])) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            return true;
        }

        return (new DefaultValueBinder())->bindValue($cell, $value);
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
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF1F4E78'], // ARGB correto com opacidade (FF)
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        $filiais = Filial::pluck('codigo')->toArray();
        $motoristas = Motorista::pluck('codigo')->toArray();

        return [
            AfterSheet::class => function (AfterSheet $event) use ($filiais, $motoristas) {
                $spreadsheet = $event->sheet->getDelegate()->getParent();

                // 1. Cria uma aba auxiliar para armazenar as listas
                $listSheet = $spreadsheet->createSheet();
                $listSheet->setTitle('Motoristas');

                // Oculta a aba para o usuário final não ver
                $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

                // 2. Preenche a coluna A da aba auxiliar com as Filiais
                foreach ($filiais as $index => $codigo) {
                    $row = $index + 1;
                    $listSheet->setCellValue("A{$row}", $codigo);
                }
                $totalFiliais = count($filiais);

                // 3. Preenche a coluna B da aba auxiliar com os Motoristas
                foreach ($motoristas as $index => $codigo) {
                    $row = $index + 1;
                    $listSheet->setCellValue("B{$row}", $codigo);
                }
                $totalMotoristas = count($motoristas);

                // 4. Aplica as validações apontando para o intervalo de células
                $mainSheet = $event->sheet->getDelegate();

                if ($totalFiliais > 0) {
                    $validationA = new DataValidation();
                    $validationA->setType(DataValidation::TYPE_LIST);
                    $validationA->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validationA->setAllowBlank(true);
                    $validationA->setShowDropDown(true);
                    // Aponta para a coluna A da aba oculta
                    $validationA->setFormula1("Motoristas!\$A\$1:\$A\${$totalFiliais}");
                    $mainSheet->setDataValidation("A2:A10000", $validationA);
                }

                if ($totalMotoristas > 0) {
                    $validationE = new DataValidation();
                    $validationE->setType(DataValidation::TYPE_LIST);
                    $validationE->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validationE->setAllowBlank(true);
                    $validationE->setShowDropDown(true);
                    // Aponta para a coluna B da aba oculta
                    $validationE->setFormula1("Motoristas!\$B\$1:\$B\${$totalMotoristas}");
                    $mainSheet->setDataValidation("E2:E10000", $validationE);
                }
            }
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,
            'B' => 15,
            'C' => 15,
            'D' => 15,
            'E' => 15,
            'F' => 50,
        ];
    }
}
