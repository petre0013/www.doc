<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['document_id', 'direction', 'format', 'source_name', 'status', 'result_path', 'error', 'created_by_id', 'ip'])]
class ConversionJob extends Model
{
    /** @use HasFactory<\Database\Factories\ConversionJobFactory> */
    use HasFactory, Prunable;

    public function prunable()
    {
        return static::where('created_at', '<', now()->subHours(24));
    }

    protected function pruning()
    {
        if ($this->result_path) {
            Storage::disk('local')->delete($this->result_path);
        }
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The user who started the conversion (queue workers run unauthenticated). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
