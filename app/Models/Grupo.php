<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Grupo de permissão. O perfil define a área do app (administrador, monitoramento
 * ou motorista); menus e ações liberados ficam vinculados ao grupo.
 */
class Grupo extends Model
{
    use HasUuids;

    protected $table = 'grupos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['nome', 'descricao', 'perfil', 'sistema'];

    protected $casts = ['sistema' => 'boolean'];

    public const PERFIS = ['administrador', 'monitoramento', 'motorista'];

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'grupo_menus', 'grupo_id', 'menu_id');
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'grupo_id');
    }

    /** Chaves das ações liberadas para o grupo. */
    public function permissoes(): array
    {
        return DB::table('grupo_permissoes')->where('grupo_id', $this->id)->pluck('permissao')->all();
    }

    public function sincronizarPermissoes(array $permissoes): void
    {
        $validas = array_values(array_intersect(array_unique($permissoes), array_keys(config('permissoes.acoes'))));

        DB::table('grupo_permissoes')->where('grupo_id', $this->id)->delete();
        DB::table('grupo_permissoes')->insert(array_map(fn ($p) => ['grupo_id' => $this->id, 'permissao' => $p], $validas));
    }

    /** Grupo de sistema correspondente a um perfil (role) legado. */
    public static function doPerfil(string $perfil): ?self
    {
        return self::query()->where('sistema', true)->where('perfil', $perfil)->first();
    }
}
