<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TrocasClientesExport implements FromCollection, WithHeadings, WithColumnWidths, WithStyles
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => [$row->cliente, (int) $row->quantidade_trocada]);
    }

    public function headings(): array
    {
        return ['Cliente', 'Quantidade Trocada'];
    }

    public function columnWidths(): array
    {
        return ['A' => 45, 'B' => 22];
    }

    /** Mantém o mesmo cabeçalho dos modelos de importação. */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => Color::COLOR_WHITE],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => '1F4E78'],
                ],
            ],
        ];
    }
}
