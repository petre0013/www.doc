<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One row in the append-only audit trail. Immutable by construction: updates
 * and deletes throw. Retention pruning (`audit:prune`) bypasses the model via
 * a query-builder delete on purpose — that is the ONE sanctioned removal path.
 */
#[Fillable(['user_id', 'event', 'auditable_type', 'auditable_id', 'workspace_id', 'context', 'ip', 'created_at'])]
class AuditEvent extends Model
{
    /** @use HasFactory<\Database\Factories\AuditEventFactory> */
    use HasFactory;
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function ip(): Attribute
    {
        $normalizeIp = function (?string $value) {
            if ($value === '::1') {
                return '127.0.0.1';
            }
            if ($value !== null && str_starts_with($value, '::ffff:')) {
                return substr($value, 7);
            }
            return $value;
        };

        return Attribute::make(
            get: $normalizeIp,
            set: $normalizeIp
        );
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit events are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Audit events are immutable.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
