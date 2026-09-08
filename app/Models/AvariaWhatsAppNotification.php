<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AvariaWhatsAppNotification extends Model
{
    use HasUuids;

    protected $table = 'avaria_whatsapp_notifications';
    public const EVENT_REPORT = 'import_report';
    public const EVENT_APPROVED = 'avaria_approved';
    public const EVENT_REJECTED = 'avaria_rejected';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_REQUIRES_PHONE = 'requires_phone';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'avaria_id',
        'correlation_id',
        'event',
        'status',
        'phone',
        'error_code',
        'error_message',
        'attempts',
        'corrected_by',
        'queued_at',
        'last_attempt_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function avaria()
    {
        return $this->belongsTo(Avaria::class);
    }
}
