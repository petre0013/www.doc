<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_document_id', 'target_document_id', 'target_title', 'context'])]
class Link extends Model
{
    /** @use HasFactory<\Database\Factories\LinkFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'target_document_id');
    }
}
