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
        $usuario_responsavel_id = config('auth.default_sys_uuid', '4be3c49f-7fe4-45db-a3b4-e80cf45e9247');

        $tiposAvaria = [
            [
                'nome' => 'Avariado',
                'descricao' => 'Quebra, produto mal-cheio ou furado',
            ],
            [
                'nome' => 'Inversão',
                'descricao' => 'Produtos faltantes, carga incompleta',
            ],
            [
                'nome' => 'Faltante',
                'descricao' => 'Troca de produtos a serem entregues',
            ],
        ];

        foreach ($tiposAvaria as $tipoAvaria) {
            TipoAvaria::create([
                'nome' => $tipoAvaria['nome'],
                'descricao' => $tipoAvaria['descricao'],
                'usuario_responsavel_id' => $usuario_responsavel_id
            ]);
        }
    }
}
