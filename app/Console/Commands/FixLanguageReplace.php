<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use App\Services\AuthorMerger;
use App\Services\CatalogueRecords;
use App\Services\PublisherMerger;
use App\Services\SiteRevalidator;
use App\Support\LanguageReplaceFix;
use App\Support\SearchKeys;
use App\Support\UzbekText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repairs names and titles damaged by a find-and-replace of "uz", "ru" and
 * "en" (LanguageReplaceFix). In two steps, so a person sees every change:
 *
 *   php artisan catalogue:fix-language-replace
 *       writes the proposed changes to a CSV and changes nothing;
 *   php artisan catalogue:fix-language-replace --apply=<that CSV>
 *       applies the rows marked apply=yes whose value has not changed since.
 *
 * A renamed author or publisher gets the slug of its new name and keeps the
 * old name and slug as an alias, so old links still work. One whose fixed
 * name is already in the catalogue ("StivInglizcha King" beside "Stiven
 * King") is merged into it instead.
 */
#[Signature('catalogue:fix-language-replace
    {--csv= : Where to write the proposed changes (default: storage/app/language-replace-fixes.csv)}
    {--apply= : Apply the rows marked apply=yes in this CSV}')]
#[Description('Find and repair names garbled by a replace of uz/ru/en with O\'zbekcha/Ruscha/Inglizcha')]
class FixLanguageReplace extends Command
{
    /** Where the replacement reached: plain text columns of the catalogue. */
    public const COLUMNS = [
        'books' => ['uzbek_title', 'original_title', 'description'],
        'editions' => ['title', 'description', 'excerpt_source_text', 'excerpt_translated_text'],
        'authors' => ['name', 'original_name'],
        'publishers' => ['name'],
        'translators' => ['name'],
        'series' => ['name'],
    ];

    private const HEADER = ['table', 'id', 'column', 'current', 'proposed', 'sure', 'apply'];

    public function handle(): int
    {
        return filled($this->option('apply')) ? $this->apply((string) $this->option('apply')) : $this->propose();
    }

    private function propose(): int
    {
        $file = $this->option('csv') ?: storage_path('app/language-replace-fixes.csv');
        $handle = fopen($file, 'w');
        fputcsv($handle, self::HEADER, escape: '');
        $counts = [];

        foreach (self::proposals() as $row) {
            fputcsv($handle, [$row['table'], $row['id'], $row['column'], $row['current'], $row['proposed'], $row['certain'] ? 'yes' : 'check', $row['certain'] ? 'yes' : 'no'], escape: '');
            $key = "{$row['table']}.{$row['column']}";
            $counts[$key] ??= ['sure' => 0, 'check' => 0];
            $counts[$key][$row['certain'] ? 'sure' : 'check']++;
        }

        fclose($handle);

        $this->table(['column', 'sure', 'to check'], collect($counts)->map(fn (array $c, string $k) => [$k, $c['sure'], $c['check']])->values()->all());
        $this->info("Nothing changed. Proposed changes are in {$file}: rows marked \"check\" have apply=no; set apply=yes on the ones that are right, then run with --apply={$file}");

        return self::SUCCESS;
    }

    /**
     * Every damaged value in the catalogue, with its repair.
     *
     * @return \Generator<array{table: string, id: int, column: string, current: string, proposed: string, certain: bool}>
     */
    public static function proposals(): \Generator
    {
        foreach (self::COLUMNS as $table => $columns) {
            $query = DB::table($table)->select(['id', ...$columns])->where(function ($query) use ($columns) {
                foreach ($columns as $column) {
                    foreach (['Inglizcha', 'Ruscha', "O'zbekcha"] as $token) {
                        $query->orWhere($column, 'like', "%{$token}%");
                    }
                }
            });

            foreach ($query->orderBy('id')->lazyById(500) as $record) {
                foreach ($columns as $column) {
                    $fix = LanguageReplaceFix::fix($record->{$column});

                    if ($fix !== null) {
                        yield ['table' => $table, 'id' => (int) $record->id, 'column' => $column, 'current' => $record->{$column}, 'proposed' => $fix['text'], 'certain' => $fix['certain']];
                    }
                }
            }
        }
    }

    private function apply(string $file): int
    {
        $handle = is_file($file) ? fopen($file, 'r') : false;

        if ($handle === false) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $header = array_map(fn ($column) => trim(str_replace("\u{FEFF}", '', (string) $column)), fgetcsv($handle, escape: '') ?: []);

        if (array_diff(self::HEADER, $header) !== []) {
            $this->error('The CSV must have the columns '.implode(', ', self::HEADER).'.');

            return self::FAILURE;
        }

        $done = ['changed' => 0, 'renamed' => 0, 'merged' => 0, 'stale' => 0, 'skipped' => 0];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $values);

            if (! in_array(strtolower(trim($row['apply'])), ['yes', 'y', '1'], true)
                || ! isset(self::COLUMNS[$row['table']]) || ! in_array($row['column'], self::COLUMNS[$row['table']], true)
                || trim($row['proposed']) === '') {
                $done['skipped']++;

                continue;
            }

            $current = DB::table($row['table'])->where('id', (int) $row['id'])->value($row['column']);

            // Edited by someone since the CSV was written, or applied already.
            if ($current !== $row['current']) {
                $done['stale']++;

                continue;
            }

            $done[$this->repair($row['table'], (int) $row['id'], $row['column'], $row['current'], trim($row['proposed']))]++;
        }

        fclose($handle);

        SearchKeys::rebuild();
        app(SiteRevalidator::class)->everything();

        $this->info(sprintf(
            '%d value(s) fixed, %d author(s)/publisher(s) renamed, %d merged into one already there; %d changed since the CSV was written, %d not marked to apply.',
            $done['changed'], $done['renamed'], $done['merged'], $done['stale'], $done['skipped'],
        ));

        return self::SUCCESS;
    }

    /** @return 'changed'|'renamed'|'merged' */
    private function repair(string $table, int $id, string $column, string $old, string $new): string
    {
        if ($table === 'authors' && $column === 'name') {
            return $this->renameAuthor(Author::findOrFail($id), $new);
        }

        if ($table === 'publishers' && $column === 'name') {
            return $this->renamePublisher(Publisher::findOrFail($id), $new);
        }

        DB::table($table)->where('id', $id)->update([$column => $new, 'updated_at' => now()]);

        return 'changed';
    }

    private function renameAuthor(Author $author, string $name): string
    {
        $existing = app(CatalogueRecords::class)->findAuthor($name);

        if ($existing !== null && ! $existing->is($author)) {
            app(AuthorMerger::class)->merge($author, $existing);

            return 'merged';
        }

        Model::withoutEvents(function () use ($author, $name) {
            AuthorAlias::create([
                'author_id' => $author->id,
                'name' => $author->name,
                'slug' => AuthorAlias::where('slug', $author->slug)->exists() ? null : $author->slug,
            ]);

            $author->name = $name;

            if (Str::slug($name) !== $author->slug) {
                $author->slug = Author::generateUniqueSlug($name);
            }

            $author->save();
        });

        return 'renamed';
    }

    private function renamePublisher(Publisher $publisher, string $name): string
    {
        $key = UzbekText::key($name);
        $existing = Publisher::query()->whereKeyNot($publisher->id)->get(['id', 'name'])
            ->first(fn (Publisher $other) => UzbekText::key($other->name) === $key);

        if ($existing !== null) {
            app(PublisherMerger::class)->merge($publisher, Publisher::findOrFail($existing->id));

            return 'merged';
        }

        Model::withoutEvents(function () use ($publisher, $name) {
            PublisherAlias::create([
                'publisher_id' => $publisher->id,
                'name' => $publisher->name,
                'slug' => PublisherAlias::where('slug', $publisher->slug)->exists() ? null : $publisher->slug,
            ]);

            $publisher->name = $name;

            if (Str::slug($name) !== $publisher->slug) {
                $publisher->slug = Publisher::generateUniqueSlug($name);
            }

            $publisher->save();
        });

        return 'renamed';
    }
}
