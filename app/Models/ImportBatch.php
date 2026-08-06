<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
  use HasUuids;

  protected $table = 'import_batches';

  public $incrementing = false;

  protected $keyType = 'string';

  protected $fillable = [
    'type',
    'status',
    'total_rows',
    'processed_rows',
    'percentage',
    'last_log',
    'current_step',
  ];

  protected $casts = [
    'total_rows' => 'int',
    'processed_rows' => 'int',
    'percentage' => 'int',
  ];
}
