<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['document_id', 'document_version_id', 'user_id', 'user_name', 'user_email', 'acknowledged_at'])]
class DocumentAcknowledgement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
