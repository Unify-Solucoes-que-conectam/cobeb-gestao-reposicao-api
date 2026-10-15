<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('menus')->where('rota', '/admin/vendas-trocas')->exists()) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(), 'titulo' => 'Vendas e Trocas', 'icone' => 'ArrowLeftRight',
                'rota' => '/admin/vendas-trocas', 'ordem' => 3, 'menu_pai_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('rota', '/admin/vendas-trocas')->delete();
    }
};
