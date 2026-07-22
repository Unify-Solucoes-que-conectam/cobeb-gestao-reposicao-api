<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\ImportBatch;

Broadcast::channel('imports.{batchId}', function ($user, $batchId) {
    return ImportBatch::query()
        ->where('id', $batchId)
        ->where('user_id', $user->id)
        ->exists();
});
