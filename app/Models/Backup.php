<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['status', 'trigger', 'disk', 'path', 'size_bytes', 'manifest', 'error', 'started_at', 'finished_at', 'created_by_id', 'ip', 'report_emailed', 'report_error', 'acknowledged_at', 'restore_status', 'restore_error', 'restored_at'])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'manifest'        => 'array',
            'size_bytes'      => 'integer',
            'started_at'      => 'datetime',
            'finished_at'     => 'datetime',
            'report_emailed'  => 'boolean',
            'acknowledged_at' => 'datetime',
            'restored_at'     => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
