<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConfiguration extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_configurations';
    public const PROVIDER_OFFICIAL = 'official';
    public const PROVIDER_BAILEYS = 'baileys';

    protected $fillable = [
        'filial_id', 'is_global', 'global_slot', 'provider', 'instance_name', 'instance_id',
        'instance_api_key', 'meta_access_token', 'meta_phone_number_id',
        'meta_business_account_id', 'token_expires_at', 'status',
        'connected_phone', 'connected_at', 'last_checked_at', 'last_error', 'created_by', 'updated_by',
    ];

    protected $hidden = ['instance_api_key', 'meta_access_token'];

    protected function casts(): array
    {
        return [
            'instance_api_key' => 'encrypted',
            'meta_access_token' => 'encrypted',
            'is_global' => 'boolean',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function filial()
    {
        return $this->belongsTo(Filial::class);
    }

    public function templates()
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_configuration_id');
    }

    public static function resolveForFilial(string $filialId): ?self
    {
        return static::query()
            ->with('templates')
            ->where('is_global', true)
            ->first()
            ?? static::query()->with('templates')->where('filial_id', $filialId)->first()
        ;
    }
}
