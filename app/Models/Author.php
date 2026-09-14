<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'slug',
    'name',
    'bio',
    'nationality',
    'birth_year',
    'death_year',
    'portrait_path',
    'website_url',
])]
class Author extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['bio', 'nationality'];

    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'death_year' => 'integer',
        ];
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function getPortraitUrlAttribute(): ?string
    {
        return $this->portrait_path ? '/storage/'.$this->portrait_path : null;
    }
}