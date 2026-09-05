<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('required_role', 30)->nullable()->after('menu_pai_id');
        });

        DB::table('menus')->where('titulo', 'Usuários')->update(['required_role' => 'administrador']);

        $parentId = DB::table('menus')->where('titulo', 'Gerenciar')->value('id');

        if ($parentId && !DB::table('menus')->where('titulo', 'WhatsApp')->exists()) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'titulo' => 'WhatsApp',
                'icone' => 'MessageCircleMore',
                'rota' => '/admin/gerenciar/whatsapp',
                'ordem' => 4,
                'menu_pai_id' => $parentId,
                'required_role' => 'administrador',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('titulo', 'WhatsApp')->where('rota', '/admin/gerenciar/whatsapp')->delete();
        DB::table('menus')->where('titulo', 'Usuários')->update(['required_role' => null]);
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('required_role');
        });
    }
};
