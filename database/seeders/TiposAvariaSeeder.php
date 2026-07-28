<?php

namespace Database\Seeders;

use App\Models\TipoAvaria;
use Illuminate\Database\Seeder;

class TiposAvariaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $tiposAvaria = [
            [
                'codigo' => '5',
                'nome' => 'Avariado',
                'descricao' => 'Quebra, produto mal-cheio ou furado',
            ],
            [
                'codigo' => '39',
                'nome' => 'Inversão',
                'descricao' => 'Produtos faltantes, carga incompleta',
            ],
            // [
            //     'nome' => 'Faltante',
            //     'descricao' => 'Troca de produtos a serem entregues',
            // ],
        ];

        foreach ($tiposAvaria as $tipoAvaria) {
            TipoAvaria::create([
                'codigo' => $tipoAvaria['codigo'],
                'nome' => $tipoAvaria['nome'],
                'descricao' => $tipoAvaria['descricao'],
            ]);
        }
    }
}
