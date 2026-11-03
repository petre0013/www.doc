<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Auto-generates a URL slug from a source attribute when none is set,
 * guaranteeing uniqueness within an optional scope.
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::saving(function (Model $model) {
            if (blank($model->slug)) {
                $model->slug = $model->generateUniqueSlug((string) $model->slugSource());
            }
        });
    }

    /** The attribute the slug is derived from. */
    protected function slugSource(): string
    {
        return (string) ($this->name ?? $this->title ?? '');
    }

    /** Extra column => value constraints the slug must be unique within. */
    protected function slugScope(): array
    {
        return [];
    }

    public function generateUniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 1;

        while ($this->slugExists($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    protected function slugExists(string $slug): bool
    {
        $query = static::query()->where('slug', $slug);

        // Trashed rows still occupy the slug's unique space at the DB level, so
        // they must count here — otherwise a restored row would collide with a
        // newer one that reused its slug.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        foreach ($this->slugScope() as $column => $value) {
            $query->where($column, $value);
        }

        return $query->exists();
    }
}
