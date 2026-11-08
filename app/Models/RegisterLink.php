<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['register_item_id', 'document_id', 'linked_item_id'])]
class RegisterLink extends Model
{
    public $timestamps = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(RegisterItem::class, 'linked_item_id');
    }
}
