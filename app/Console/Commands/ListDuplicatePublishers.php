<?php

namespace App\Console\Commands;

use App\Models\Publisher;
use App\Support\UzbekText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('publishers:duplicates')]
#[Description('List publishers whose names look like one publisher spelt two ways, for an admin to merge')]
class ListDuplicatePublishers extends Command
{
    public function handle(): int
    {
        $groups = Publisher::withBookCount()
            ->orderBy('id')
            ->get(['id', 'name', 'slug'])
            ->groupBy(fn (Publisher $publisher) => UzbekText::publisherKey($publisher->name))
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

        $this->line("{$groups->count()} groups. These are guesses: check each is one publisher before merging it in Publishers → Merge into…");

        return self::SUCCESS;
    }
}
