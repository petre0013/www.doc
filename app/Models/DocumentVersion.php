<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'title', 'content', 'content_html', 'tags', 'attachments', 'summary', 'created_by_id'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'tags' => 'array',
            'attachments' => 'array',
            'summary' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
