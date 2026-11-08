<?php

namespace App\Models;

use App\Support\Registers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['type', 'reference', 'title', 'status', 'owner_id', 'due_at', 'data'])]
class RegisterItem extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['data' => 'array', 'due_at' => 'date'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(RegisterLink::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, Registers::closedStatuses($this->type), true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lt(today());
    }
}
