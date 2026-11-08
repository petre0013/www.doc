<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['document_id', 'disk', 'path', 'original_name', 'mime', 'size', 'checksum', 'uploaded_by_id', 'position'])]
class Attachment extends Model
{
    /** @use HasFactory<\Database\Factories\AttachmentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Removing an attachment from a page is a soft delete: the file stays as
        // evidence for the versions that list it. A document purge cascades rows
        // at the DB level; DocumentObserver::forceDeleting removes those files.
        static::forceDeleting(function (Attachment $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
