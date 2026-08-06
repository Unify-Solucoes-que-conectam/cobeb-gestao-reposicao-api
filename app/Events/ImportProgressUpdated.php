<?php

namespace App\Events;

use App\Models\ImportBatch;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ImportBatch $batch;

    public function __construct(ImportBatch $batch)
    {
        $this->batch = $batch;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('imports.' . $this->batch->id);
    }

    public function broadcastAs(): string
    {
        return 'import.progress.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->batch->id,
            'type' => $this->batch->type,
            'status' => $this->batch->status,
            'total_rows' => $this->batch->total_rows,
            'processed_rows' => $this->batch->processed_rows,
            'percentage' => $this->batch->percentage,
            'last_log' => $this->batch->last_log,
            'current_step' => $this->batch->current_step,
            'updated_at' => $this->batch->updated_at?->toISOString(),
        ];
    }
}
