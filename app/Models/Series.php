<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Books meant to be read in order; `books.series_position` says where each goes. */
#[Fillable(['slug', 'name'])]
class Series extends Model
{
    use GeneratesUniqueSlug;

    protected $table = 'series';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->orderBy('series_position');
    }
}
