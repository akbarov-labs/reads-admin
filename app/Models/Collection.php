<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use App\Observers\CollectionObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * An editor's list of books around translation, in its own order, shown on
 * the site once published. Named "collection" here and "toʻplam" on the
 * site; not to be confused with a Laravel Collection.
 */
#[ObservedBy(CollectionObserver::class)]
#[Fillable(['slug', 'title', 'description', 'is_published', 'position'])]
class Collection extends Model
{
    use GeneratesUniqueSlug;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<CollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class)->orderBy('position')->orderBy('id');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('collections.is_published', true);
    }
}
