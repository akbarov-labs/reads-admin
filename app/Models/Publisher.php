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
    'country',
    'established_year',
    'logo_path',
    'website_url',
])]
class Publisher extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['bio', 'country'];

    protected function casts(): array
    {
        return ['established_year' => 'integer'];
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }
}