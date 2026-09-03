<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_templates';
    public const EVENTS = ['avaria_approved', 'avaria_rejected', 'import_report', 'manual_notification'];

    protected $fillable = [
        'whatsapp_configuration_id', 'event', 'template_name', 'language_code', 'status',
    ];

    public function configuration()
    {
        return $this->belongsTo(WhatsAppConfiguration::class, 'whatsapp_configuration_id');
    }
}
