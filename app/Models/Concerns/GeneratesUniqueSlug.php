<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * `slug` is required and unique on every catalogue table, but a row created
 * inline from a form — a translator, author or publisher named while adding
 * a book — supplies only a name. This picks a free slug for it; an admin can
 * change it later.
 */
trait GeneratesUniqueSlug
{
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::snake(class_basename(static::class), '-');

        // An all-digit slug would be read as a primary key by the route
        // binding that falls back to ids.
        if (ctype_digit($base)) {
            $base = Str::snake(class_basename(static::class), '-').'-'.$base;
        }

        $slug = $base;

        for ($suffix = 2; static::slugTaken($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    protected static function slugTaken(string $slug): bool
    {
        return static::where('slug', $slug)->exists();
    }
}
