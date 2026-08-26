<?php

namespace App\Exports;

use App\Models\Cluster;
use App\Models\Filial;
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

class MotoristasExport implements FromArray, WithHeadings, WithEvents, WithStyles, WithColumnWidths
{

    public function headings(): array
    {
        /**
         * CPF, Cód.Motorista, Nome Motorista, Cód.Cluster, Cód.Filial, Data Admissão, Data Inativação
         */
        return [
            'CPF',
            'Cód.Motorista',
            'Nome Motorista',
            'Cód.Cluster',
            'Cód.Filial',
            'Data Admissão',
            'Data Inativação'
        ];
    }

    public function array(): array
    {

        return [
            [
                '12345678901',
                '1',
                'João da Silva',
                '10',
                '1',
                '01/01/2026',
                '01/01/2026'
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

    public function registerEvents(): array
    {
        $clusters = Cluster::all();
        $filiais = Filial::all();

        // Mapeia a coluna para suas respectivas opções
        $validacoes = [
            'D' => '"' . $clusters->pluck('codigo')->implode(',') . '"',
            'E' => '"' . $filiais->pluck('codigo')->implode(',') . '"',
        ];

        return [
            AfterSheet::class => function (AfterSheet $event) use ($validacoes) {
                foreach ($validacoes as $column => $formula) {
                    // 1. Instancia o objeto de validação
                    $validation = new DataValidation();
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                    $validation->setAllowBlank(false);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setFormula1($formula);

                    // 2. Aplica a validação no intervalo desejado da planilha
                    $event->sheet->getDelegate()->setDataValidation("{$column}2:{$column}10000", $validation);
                }
            }
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15, // CPF
            'B' => 15, // Cód.Motorista
            'C' => 30, // Nome Motorista
            'D' => 15, // Cód.Cluster
            'E' => 15, // Cód.Filial
            'F' => 20, // Data Admissão
            'G' => 20, // Data Inativação
        ];
    }
}
