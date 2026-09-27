<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shelf a book sits on: a genre ("Jahon adabiyoti"), an audience
 * ("Bolalar adabiyoti") or a list ("TOP-100 bestseller"). A book can be on
 * several; the one marked primary on the pivot is its genre.
 */
#[Fillable(['parent_id', 'slug', 'name', 'source_path', 'sort_order'])]
class Category extends Model
{
    use GeneratesUniqueSlug;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /** @return BelongsToMany<Book, $this> */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withPivot('is_primary');
    }
}
