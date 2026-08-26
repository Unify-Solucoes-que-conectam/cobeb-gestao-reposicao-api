<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;

class ProdutosExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithColumnFormatting
{

    public function headings(): array
    {
        /**
         * Código, EAN, Descrição, Tipo Marca, Embalagem
         */
        return [
            'Código',
            'EAN',
            'Descrição',
            'Tipo Marca',
            'Embalagem',
        ];
    }

    public function array(): array
    {

        return [
            [
                '1',
                '1234567890123',
                'Produto Exemplo',
                '001 - Marca Exemplo',
                '001 - Embalagem Exemplo',
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
                    'size' => 11
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => '1F4E78'], // Azul escuro
                ],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15, // Código
            'B' => 20, // EAN
            'C' => 50, // Descrição
            'D' => 30, // Tipo Marca
            'E' => 30, // Embalagem
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => '0', // Força a coluna B (EAN) a exibir todos os dígitos
        ];
    }
}
