<?php

namespace Database\Seeders;

use App\Models\Filial;
use Illuminate\Database\Seeder;

class FiliaisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Filiais disponíveis atualmente
        $filiais = [
            [
                'codigo' => '1',
                'descricao' => 'COBEB - MATRIZ'
            ],
            [
                'codigo' => '2',
                'descricao' => 'COBEB - LAGOA'
            ],
            [
                'codigo' => '5',
                'descricao' => 'COBEB - RDC ABAETE'
            ]
        ];

        foreach ($filiais as $filial) {
            Filial::create([
                'codigo' => $filial['codigo'],
                'descricao' => $filial['descricao'],
            ]);
        }
    }
}
