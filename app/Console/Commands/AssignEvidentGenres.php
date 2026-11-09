<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Category;
use App\Services\SiteRevalidator;
use App\Support\Genres;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('books:assign-genres {--dry-run : Count and list, change nothing}')]
#[Description('Give each book on shelves but with no genre the genre its shelves make plain')]
class AssignEvidentGenres extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $categories = Category::all()->keyBy('id');

        // Book → its shelves, for books on at least one shelf and with no primary.
        $shelves = DB::table('book_category')
            ->whereNotIn('book_id', DB::table('book_category')->where('is_primary', true)->select('book_id'))
            ->orderBy('book_id')
            ->get(['book_id', 'category_id'])
            ->groupBy('book_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => $categories[$row->category_id])->values());

        $assigned = [];
        $undecided = [];

        foreach ($shelves as $bookId => $onShelves) {
            $genre = Genres::evident($onShelves);

            if ($genre === null) {
                $key = $onShelves->pluck('name')->sort()->implode(' + ');
                $undecided[$key] = ($undecided[$key] ?? 0) + 1;

                continue;
            }

            $assigned[] = [$bookId, $genre];
        }

        if (! $dryRun && $assigned !== []) {
            $log = ['book_id,category_id,category'];

            foreach ($assigned as [$bookId, $genre]) {
                Book::findOrFail($bookId)->setGenre($genre);
                $log[] = $bookId.','.$genre->id.',"'.str_replace('"', '""', $genre->name).'"';
            }

            // To undo: set is_primary = 0 for these book/category pairs.
            $path = 'genre-assignments/'.now()->format('Y-m-d_His').'.csv';
            Storage::disk('local')->put($path, implode("\n", $log)."\n");
            $this->line('Logged to '.Storage::disk('local')->path($path));

            // One ping for all of it: genres show on every book page and listing.
            app(SiteRevalidator::class)->everything();
        }

        $byGenre = collect($assigned)->countBy(fn ($pair) => $pair[1]->name)->sortDesc();
        $this->table(['Genre', 'Books'], $byGenre->map(fn ($count, $name) => [$name, $count])->values()->all());

        arsort($undecided);
        $this->table(['Shelves with no plain genre (top 20)', 'Books'], collect($undecided)->take(20)->map(fn ($count, $key) => [$key, $count])->values()->all());

        $this->info(sprintf(
            '%s %d of %d books; %d left for a person to choose.',
            $dryRun ? 'Would give a genre to' : 'Gave a genre to',
            count($assigned),
            $shelves->count(),
            array_sum($undecided),
        ));

        return self::SUCCESS;
    }
}
