<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable starting point for new pages. Deliberately NOT a Document: no
 * versions, no wiki-link parsing, no search vector, no soft deletes — its
 * content only becomes any of those things once copied into a real page.
 */
#[Fillable(['name', 'description', 'content', 'created_by_id'])]
class Template extends Model
{
    /** @use HasFactory<\Database\Factories\TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
