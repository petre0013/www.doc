<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One entry in the append-only sign-off record; the database refuses changes. */
#[Fillable(['document_id', 'document_version_id', 'action', 'document_title', 'doc_number', 'content_hash', 'actor_id', 'actor_name', 'actor_email', 'comment', 'created_at'])]
class DocumentControlEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
