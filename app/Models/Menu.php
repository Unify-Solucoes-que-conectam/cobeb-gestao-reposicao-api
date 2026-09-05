<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use HasUuids;

    protected $table = 'menus';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'titulo',
        'icone',
        'rota',
        'ordem',
        'menu_pai_id',
        'required_role',
    ];

    public function menu_pai()
    {
        return $this->belongsTo(Menu::class, 'menu_pai_id');
    }

    public function subMenus()
    {
        return $this->hasMany(Menu::class, 'menu_pai_id');
    }
}
