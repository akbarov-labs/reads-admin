<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Support\UzbekText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('authors:duplicates')]
#[Description('List authors whose names look like one person spelt two ways, for an admin to merge')]
class ListDuplicateAuthors extends Command
{
    public function handle(): int
    {
        $groups = Author::withCount('books')
            ->orderBy('id')
            ->get(['id', 'name', 'slug'])
            ->groupBy(fn (Author $author) => self::looseKey($author->name))
            ->filter(fn ($group, string $key) => $key !== '' && $group->count() > 1)
            ->sortByDesc(fn ($group) => $group->sum('books_count'));

        if ($groups->isEmpty()) {
            $this->info('No likely duplicates.');

            return self::SUCCESS;
        }

        $this->table(
            ['Names (most books first)', 'Slugs', 'Books'],
            $groups->map(function ($group) {
                $group = $group->sortByDesc('books_count');

                return [
                    $group->pluck('name')->implode(' | '),
                    $group->pluck('slug')->implode(' | '),
                    $group->pluck('books_count')->implode(' + '),
                ];
            })->values()->all(),
        );

        $this->line("{$groups->count()} groups. These are guesses: check each is one person before merging it in Book authors → Merge into…");

        return self::SUCCESS;
    }

    /**
     * Looser than UzbekText::key, which already ignores alphabet, case and
     * punctuation: h and x are one letter here (Tohir / Toxir), and so is a
     * letter typed twice (Oruell / Oruel).
     */
    public static function looseKey(string $name): string
    {
        return preg_replace('/(.)\1+/u', '$1', str_replace('x', 'h', UzbekText::key($name)));
    }
}
