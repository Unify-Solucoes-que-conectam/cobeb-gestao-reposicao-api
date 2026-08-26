<?php

namespace App\Exports;

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

class ClientesExport implements FromArray, WithHeadings, WithEvents, WithStyles, WithColumnWidths
{

    public function headings(): array
    {
        /**
         * Cód PDV, Documento, Nome Fantasia, Razão Social, Endereço, Complemento, Bairro, Cidade, UF, CEP, Filial, Categoria, Tipo de Pessoa, Status do PDV, Telefone(s)
         */
        return [
            'Cód PDV',
            'Documento',
            'Nome Fantasia',
            'Razão Social',
            'Endereço',
            'Complemento',
            'Bairro',
            'Cidade',
            'UF',
            'CEP',
            'Filial',
            'Categoria',
            'Tipo de Pessoa',
            'Status do PDV',
            'Telefone(s)',
        ];
    }

    public function array(): array
    {

        return [
            [
                '1',
                '04.367.690/0001-72',
                'Mercado Central',
                'Mercado Central LTDA',
                'Rua das Flores, 123',
                'Sala 1',
                'Centro',
                'São Paulo',
                'SP',
                '01.000-000',
                '1',
                'MERCADO',
                'Física',
                'Ativo',
                '37 9999-9999 | 37 3232-0000 | 37 99999-9999'
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
        $filiais = Filial::all();

        // Mapeia a coluna para suas respectivas opções
        $validacoes = [
            'K' => '"' . $filiais->pluck('codigo')->implode(',') . '"',
            'M' => '"Jurídica,Física"',
            'N' => '"Ativo,Inativo"',
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
            'A' => 10, // Cód PDV
            'B' => 20, // Documento
            'C' => 30, // Nome Fantasia
            'D' => 30, // Razão Social
            'E' => 40, // Endereço
            'F' => 20, // Complemento
            'G' => 20, // Bairro
            'H' => 20, // Cidade
            'I' => 5,  // UF
            'J' => 10, // CEP
            'K' => 10, // Filial
            'L' => 20, // Categoria
            'M' => 20, // Tipo de Pessoa
            'N' => 15, // Status do PDV
            'O' => 80, // Telefone(s)
        ];
    }
}
