<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Book;
use App\Models\Translator;
use App\Services\AuthorMerger;
use App\Services\CatalogueRecords;
use App\Services\SiteRevalidator;
use App\Support\SearchKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies a reviewed list of catalogue corrections: books credited to the
 * wrong author, one person kept under several spellings, names stored in
 * the wrong grammatical form. Without --apply it only says what it would
 * do. Every step checks the catalogue still looks the way the list
 * expects, and leaves alone anything that has changed since.
 *
 * The list shipped with the app (database/data/catalogue-fixes-2026-11.json)
 * comes from the author and translator research of 2026-11-12.
 */
#[Signature('catalogue:apply-fixes
    {--file= : JSON file to read (default: database/data/catalogue-fixes-2026-11.json)}
    {--apply : Make the changes (without it, only show them)}')]
#[Description('Fix misattributed books, duplicate authors and misspelled names from a reviewed list')]
class ApplyCatalogueFixes extends Command
{
    /** @var list<string> */
    private array $skipped = [];

    private int $done = 0;

    public function handle(AuthorMerger $merger, CatalogueRecords $records): int
    {
        // The same instance answers every call in one process.
        $this->skipped = [];
        $this->done = 0;

        $file = $this->option('file') ?: database_path('data/catalogue-fixes-2026-11.json');
        $fixes = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($fixes)) {
            $this->error("Cannot read {$file} as JSON.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Applying fixes.' : 'Dry run: nothing is changed. Add --apply to make these changes.');

        // Books first: a book leaving an author must go before that author
        // is merged into someone else.
        foreach ($fixes['move_books'] ?? [] as $fix) {
            $this->moveBook($fix, $apply, $records);
        }

        foreach ($fixes['add_first_author'] ?? [] as $fix) {
            $this->addFirstAuthor($fix, $apply);
        }

        foreach ($fixes['merge_authors'] ?? [] as $fix) {
            $this->mergeAuthors($fix, $apply, $merger);
        }

        foreach ($fixes['rename_authors'] ?? [] as $fix) {
            $author = Author::query()->where('slug', $fix['slug'] ?? '')->first();
            $author ? $this->renameAuthor($author, $fix['name'] ?? null, $fix['original_name'] ?? null, $apply) : $this->skip("author {$fix['slug']} not found");
        }

        foreach ($fixes['rename_translators'] ?? [] as $fix) {
            $this->renameTranslator($fix, $apply);
        }

        if ($apply && $this->done > 0) {
            SearchKeys::rebuild();
            app(SiteRevalidator::class)->everything();
        }

        foreach ($this->skipped as $reason) {
            $this->warn("  skipped  {$reason}");
        }

        $this->info(sprintf('%d change(s) %s; %d skipped.', $this->done, $apply ? 'made' : 'to make', count($this->skipped)));

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $fix */
    private function moveBook(array $fix, bool $apply, CatalogueRecords $records): void
    {
        $book = Book::query()->find($fix['book'] ?? null);
        $from = Author::query()->where('slug', $fix['from'] ?? '')->first();
        $label = "book {$fix['book']}";

        if ($book === null || $from === null) {
            $this->skip("{$label}: ".($book === null ? 'no such book' : "no author {$fix['from']}"));

            return;
        }

        if (! DB::table('book_author')->where('book_id', $book->id)->where('author_id', $from->id)->exists()) {
            $this->skip("{$label} «{$book->uzbek_title}» is no longer credited to {$from->name}");

            return;
        }

        $to = isset($fix['to'])
            ? Author::query()->where('slug', $fix['to'])->first()
            : ($apply ? $records->author((string) $fix['to_name']) : $records->findAuthor((string) $fix['to_name']));
        $toLabel = $to?->name ?? (($fix['to_name'] ?? $fix['to'] ?? '?').' (new)');

        if (isset($fix['to']) && $to === null) {
            $this->skip("{$label}: no author {$fix['to']}");

            return;
        }

        $this->line("  move     «{$book->uzbek_title}»: {$from->name} → {$toLabel}");
        $this->done++;

        if (! $apply) {
            return;
        }

        DB::transaction(function () use ($book, $from, $to) {
            if (DB::table('book_author')->where('book_id', $book->id)->where('author_id', $to->id)->exists()) {
                DB::table('book_author')->where('book_id', $book->id)->where('author_id', $from->id)->delete();
            } else {
                DB::table('book_author')->where('book_id', $book->id)->where('author_id', $from->id)->update(['author_id' => $to->id]);
            }
        });
    }

    /** @param  array<string, mixed>  $fix */
    private function addFirstAuthor(array $fix, bool $apply): void
    {
        $author = Author::query()->where('slug', $fix['author'] ?? '')->first();

        if ($author === null) {
            $this->skip("no author {$fix['author']}");

            return;
        }

        foreach ((array) ($fix['books'] ?? []) as $id) {
            $book = Book::query()->find($id);

            if ($book === null) {
                $this->skip("book {$id}: no such book");

                continue;
            }

            if (DB::table('book_author')->where('book_id', $book->id)->where('author_id', $author->id)->exists()) {
                $this->skip("book {$id} «{$book->uzbek_title}» already credits {$author->name}");

                continue;
            }

            $this->line("  credit   «{$book->uzbek_title}»: {$author->name} first");
            $this->done++;

            if ($apply) {
                DB::transaction(function () use ($book, $author) {
                    DB::table('book_author')->where('book_id', $book->id)->increment('position');
                    DB::table('book_author')->insert(['book_id' => $book->id, 'author_id' => $author->id, 'position' => 0]);
                });
            }
        }
    }

    /** @param  array<string, mixed>  $fix */
    private function mergeAuthors(array $fix, bool $apply, AuthorMerger $merger): void
    {
        $into = Author::query()->where('slug', $fix['into'] ?? '')->first();

        if ($into === null) {
            $this->skip("no author {$fix['into']} to keep");

            return;
        }

        foreach ((array) ($fix['from'] ?? []) as $slug) {
            $duplicate = Author::query()->where('slug', $slug)->first();

            if ($duplicate === null) {
                $this->skip("{$slug}: not found (already merged?)");

                continue;
            }

            $this->line("  merge    {$duplicate->name} ({$slug}) → {$into->name}");
            $this->done++;

            if ($apply) {
                $merger->merge($duplicate, $into);
                $into->refresh();
            }
        }

        if (isset($fix['name']) || isset($fix['original_name'])) {
            $this->renameAuthor($into, $fix['name'] ?? null, $fix['original_name'] ?? null, $apply);
        }
    }

    private function renameAuthor(Author $author, ?string $name, ?string $original, bool $apply): void
    {
        $changes = array_filter([
            'name' => $name !== null && $name !== $author->name ? $name : null,
            'original_name' => $original !== null && $original !== $author->original_name ? $original : null,
        ]);

        if ($changes === []) {
            return;
        }

        $this->line('  rename   '.$author->name.' → '.implode(', ', array_map(fn ($k, $v) => "{$k} «{$v}»", array_keys($changes), $changes)));
        $this->done++;

        if (! $apply) {
            return;
        }

        // The old spelling stays an alias, so imports still find this author.
        if (isset($changes['name']) && ! AuthorAlias::query()->where('author_id', $author->id)->where('name', $author->name)->exists()) {
            AuthorAlias::create(['author_id' => $author->id, 'name' => $author->name]);
        }

        $author->fill($changes)->save();
    }

    /** @param  array<string, mixed>  $fix */
    private function renameTranslator(array $fix, bool $apply): void
    {
        $translator = Translator::query()->where('slug', $fix['slug'] ?? '')->first();

        if ($translator === null) {
            $this->skip("translator {$fix['slug']} not found");

            return;
        }

        if ($translator->name === $fix['name']) {
            return;
        }

        $this->line("  rename   translator {$translator->name} → {$fix['name']}");
        $this->done++;

        if ($apply) {
            $translator->update(['name' => $fix['name']]);
        }
    }

    private function skip(string $reason): void
    {
        $this->skipped[] = $reason;
    }
}
