<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * A book's genre is its primary shelf. Most shelves are genres, but a few
 * are lists a book is put on for another reason (a bestseller list, gift
 * sets, collectors' editions); those are never anybody's genre.
 */
class Genres
{
    /** Shelves that are lists, by slug. */
    public const LISTS = [
        'top-100-bestseller',
        'sovga-toplamlari',
        'kolleksionerlar-uchun',
    ];

    public static function isList(Category $category): bool
    {
        return in_array($category->slug, self::LISTS, true);
    }

    /** @return Collection<int, Category> every shelf that can be a genre */
    public static function choices(): Collection
    {
        return Category::orderBy('sort_order')->orderBy('name')->get()
            ->reject(fn (Category $category) => self::isList($category))
            ->values();
    }

    /**
     * The genre a book's shelves make plain, or null when they leave it to
     * a person. Lists don't count, and a shelf gives way to a sub-shelf of
     * it ("Badiiy adabiyot" + "Jahon adabiyoti" is world literature). What
     * is left must be a single shelf: "Biznes" + "Psixologiya" is a choice
     * this does not make.
     *
     * @param  iterable<Category>  $shelves
     */
    public static function evident(iterable $shelves): ?Category
    {
        $genres = collect($shelves)->reject(fn (Category $category) => self::isList($category))->unique('id');
        $parents = $genres->pluck('parent_id')->filter()->all();
        $left = $genres->reject(fn (Category $category) => in_array($category->id, $parents))->values();

        return $left->count() === 1 ? $left->first() : null;
    }
}
