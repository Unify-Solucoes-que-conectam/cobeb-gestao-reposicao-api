<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;

class UsuariosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultUuid = config('auth.default_sys_uuid');
        $defaultUser = config('auth.default_sys_user');
        $defaultPassword = config('auth.default_sys_pass');

        if ($defaultPassword === null || $defaultUuid === null || $defaultUser === null) {
            return;
        }

        Usuario::create([
            'id' => $defaultUuid,
            'nome' => $defaultUser,
            'cpf' => '00000000000',
            'senha' => $defaultPassword,
            'role' => 'administrador',
            'primeiro_acesso' => true,
        ]);
    }
}
