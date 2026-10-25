<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Which Reads shelf a Kitoblarim bot genre puts a book on. The bot's genre
 * is free text, mostly Asaxiy's shelf names as its readers and importers
 * typed them; a genre named like a shelf goes there, and ALIASES covers the
 * ones spelt otherwise. Anything else puts the book on no shelf.
 *
 * Left out on purpose (agreed 2026-10-25): "Rus/Ingliz/Arab tilida" (the
 * edition already says its language), "Ilmiy-ommabop" (no shelf like it),
 * "Badiiy bo'lmagan", "To'plam", "Boshqotirmalar", "Texnik adabiyotlar",
 * "Falsafa va madaniyat", and "-".
 */
class BotGenres
{
    /**
     * Bot genre => shelf slug, for genres not named like their shelf.
     * Compared by UzbekText::key, so case and apostrophes don't matter.
     */
    public const ALIASES = [
        'Badiiy adabiyotlar' => 'badiiy-adabiyot',
        'Roman' => 'badiiy-adabiyot',
        'Qissa va hikoyalar' => 'badiiy-adabiyot',
        "Diniy-ma'rifiy adabiyot" => 'diniy-adabiyot',
        'Diniy adabiyotlar' => 'diniy-adabiyot',
        "Qur'on" => 'diniy-adabiyot',
        "O'quv qo'llanmalar" => 'oquv-adabiyoti',
        "Ilmiy va o'quv adabiyotlari" => 'oquv-adabiyoti',
        'Tarix va Siyosat' => 'tarix',
        'Tarix va ijtimoiy fanlar' => 'tarix',
        'Fantasy' => 'detektiv-va-fantastika',
        "Umumiy lug'atlar" => 'lugat-va-sozlashgichlar',
    ];

    /** @var Collection<string, Category> keyed by UzbekText::key of the genre */
    private Collection $shelves;

    public function __construct()
    {
        $categories = Category::orderBy('id')->get();
        $bySlug = $categories->keyBy('slug');

        $this->shelves = $categories->keyBy(fn (Category $category) => UzbekText::key($category->name));

        foreach (self::ALIASES as $genre => $slug) {
            if ($bySlug->has($slug)) {
                $this->shelves[UzbekText::key($genre)] = $bySlug[$slug];
            }
        }
    }

    public function shelf(?string $genre): ?Category
    {
        $key = UzbekText::key((string) $genre);

        return $key === '' ? null : $this->shelves->get($key);
    }
}
