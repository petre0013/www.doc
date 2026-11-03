<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'avatar_color'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Pages this user starred, newest star first. Personal navigation state
     * lives in the document_user pivot, never on the document row itself.
     * Trashed pages drop out via Document's SoftDeletes scope.
     */
    public function starredDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('starred_at')
            ->wherePivotNotNull('starred_at')
            ->orderByPivot('starred_at', 'desc');
    }

    /** Pages this user opened, most recent first (same pivot as stars). */
    public function recentlyViewedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('last_viewed_at')
            ->wherePivotNotNull('last_viewed_at')
            ->orderByPivot('last_viewed_at', 'desc');
    }
}
