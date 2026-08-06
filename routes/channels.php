<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\ImportBatch;

Broadcast::channel('notifications.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('imports.{batchId}', function ($user, $batchId) {
    return ImportBatch::query()
        ->where('id', $batchId)
        ->exists();
});
