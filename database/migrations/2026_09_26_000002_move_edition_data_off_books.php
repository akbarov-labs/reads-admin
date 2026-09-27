<?php

use App\Enums\BookRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Every existing book row was, in the new vocabulary, a work with exactly
     * one edition. This splits each one accordingly and then drops the
     * columns that now live on the edition:
     *
     *   - publisher, year, cover and excerpt go to the book's one edition;
     *   - its translator becomes that edition's first (and only) translator,
     *     keeping the role;
     *   - its author becomes a book_author row. Books entered through the
     *     panel carry only the free-text name, so a name with no catalogue
     *     row gets one here — the same backfill 2026_09_14 did once already;
     *   - the author / publisher images on the book move to the catalogue
     *     rows they were always describing, where those have none yet;
     *   - each taqriz moves from the book to the book's edition.
     *
     * Book ids are untouched, so /book/{id} keeps working.
     */
    private const EDITION_COLUMNS = [
        'cover_path', 'year', 'excerpt_source_language', 'excerpt_source_text', 'excerpt_translated_text',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('books')->orderBy('id')->eachById(function (object $book): void {
                $editionId = DB::table('editions')->insertGetId([
                    'book_id' => $book->id,
                    'publisher_id' => $book->publisher_id ?? $this->publisherIdFor($book->publisher),
                    ...array_intersect_key((array) $book, array_flip(self::EDITION_COLUMNS)),
                    'status' => $book->status,
                    'sort_order' => $book->sort_order,
                    'created_at' => $book->created_at,
                    'updated_at' => $book->updated_at,
                ]);

                if ($book->translator_id !== null) {
                    DB::table('edition_translator')->insert([
                        'edition_id' => $editionId,
                        'translator_id' => $book->translator_id,
                        'role' => $book->role ?: BookRole::Translator->value,
                        'position' => 0,
                    ]);
                }

                $authorId = $book->author_id ?? $this->authorIdFor($book->author);

                if ($authorId !== null) {
                    DB::table('book_author')->insert(['book_id' => $book->id, 'author_id' => $authorId, 'position' => 0]);
                }

                if (filled($book->author_image_path) && $authorId !== null) {
                    DB::table('authors')->where('id', $authorId)->whereNull('portrait_path')
                        ->update(['portrait_path' => $book->author_image_path]);
                }

                $publisherId = DB::table('editions')->where('id', $editionId)->value('publisher_id');

                if (filled($book->publisher_image_path) && $publisherId !== null) {
                    DB::table('publishers')->where('id', $publisherId)->whereNull('logo_path')
                        ->update(['logo_path' => $book->publisher_image_path]);
                }
            });
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->foreignId('edition_id')->nullable()->after('id');
        });

        // One edition per book at this point, so the mapping is exact.
        DB::table('taqrizlar')->orderBy('id')->eachById(function (object $taqriz): void {
            DB::table('taqrizlar')->where('id', $taqriz->id)->update([
                'edition_id' => DB::table('editions')->where('book_id', $taqriz->book_id)->value('id'),
            ]);
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->foreignId('edition_id')->nullable(false)->change();
            $table->foreign('edition_id')->references('id')->on('editions')->cascadeOnDelete();
            // The new unique index goes on before the old one comes off: MySQL
            // uses whichever index leads with taqrizchi_id to back that
            // column's foreign key, and refuses to drop the last one.
            $table->unique(['taqrizchi_id', 'edition_id']);
            $table->index(['edition_id', 'status']);
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->dropForeign(['book_id']);
            $table->dropUnique(['taqrizchi_id', 'book_id']);
            $table->dropIndex(['book_id', 'status']);
            $table->dropColumn('book_id');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['translator_id']);
            $table->dropForeign(['author_id']);
            $table->dropForeign(['publisher_id']);
            $table->dropIndex(['author']);
            $table->dropIndex(['publisher']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn([
                'translator_id', 'author_id', 'publisher_id', 'role', 'author', 'author_image_path',
                'publisher', 'publisher_image_path', ...self::EDITION_COLUMNS,
            ]);

            // A work known only by its Uzbek title, from an unknown source
            // language, is a perfectly ordinary catalogue entry.
            $table->string('original_title')->nullable()->change();
            $table->string('source_language')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back folds each book's editions back into the book row.
        // That only works while nobody relies on the new shape, so refuse —
        // loudly, like 2026_09_19_000001 does — rather than silently keep one
        // edition, translator or author and delete the rest.
        $blockers = array_filter([
            'book(s) with more than one edition' => $this->countOver('editions', 'book_id'),
            'edition(s) with more than one translator' => $this->countOver('edition_translator', 'edition_id'),
            'book(s) with more than one author' => $this->countOver('book_author', 'book_id'),
        ]);

        if ($blockers !== []) {
            $summary = collect($blockers)->map(fn (int $n, string $what) => "{$n} {$what}")->implode(', ');

            throw new RuntimeException("Cannot reverse this migration: {$summary}. Reduce each to one, then roll back again.");
        }

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('translator_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->after('translator_id')->constrained()->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->after('author_id')->constrained()->nullOnDelete();
            $table->string('role')->default(BookRole::Translator->value)->after('publisher_id');
            $table->string('author')->nullable()->index();
            $table->string('author_image_path')->nullable();
            $table->string('publisher')->nullable()->index();
            $table->string('publisher_image_path')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('excerpt_source_language')->nullable();
            $table->text('excerpt_source_text')->nullable();
            $table->text('excerpt_translated_text')->nullable();
        });

        DB::table('books')->orderBy('id')->eachById(function (object $book): void {
            $edition = DB::table('editions')->where('book_id', $book->id)->first();
            $translator = $edition ? DB::table('edition_translator')->where('edition_id', $edition->id)->first() : null;
            $authorId = DB::table('book_author')->where('book_id', $book->id)->value('author_id');

            DB::table('books')->where('id', $book->id)->update([
                'translator_id' => $translator?->translator_id,
                'role' => $translator?->role ?? BookRole::Translator->value,
                'author_id' => $authorId,
                'author' => $authorId ? DB::table('authors')->where('id', $authorId)->value('name') : null,
                'publisher_id' => $edition?->publisher_id,
                'publisher' => $edition?->publisher_id ? DB::table('publishers')->where('id', $edition->publisher_id)->value('name') : null,
                ...($edition ? array_intersect_key((array) $edition, array_flip(self::EDITION_COLUMNS)) : []),
                'original_title' => $book->original_title ?? $book->uzbek_title,
                'source_language' => $book->source_language ?? '',
            ]);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->string('original_title')->nullable(false)->change();
            $table->string('source_language')->nullable(false)->change();
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable()->after('id');
        });

        DB::table('taqrizlar')->orderBy('id')->eachById(function (object $taqriz): void {
            DB::table('taqrizlar')->where('id', $taqriz->id)->update([
                'book_id' => DB::table('editions')->where('id', $taqriz->edition_id)->value('book_id'),
            ]);
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable(false)->change();
            $table->foreign('book_id')->references('id')->on('books')->cascadeOnDelete();
            $table->unique(['taqrizchi_id', 'book_id']);
            $table->index(['book_id', 'status']);
        });

        Schema::table('taqrizlar', function (Blueprint $table) {
            $table->dropForeign(['edition_id']);
            $table->dropUnique(['taqrizchi_id', 'edition_id']);
            $table->dropIndex(['edition_id', 'status']);
            $table->dropColumn('edition_id');
        });

        // The previous migration's down() drops these tables; emptying them
        // here keeps a down/up of this migration alone from doubling rows.
        DB::table('edition_translator')->delete();
        DB::table('edition_images')->delete();
        DB::table('editions')->delete();
        DB::table('book_author')->delete();
    }

    private function countOver(string $table, string $column): int
    {
        return DB::table($table)->select($column)->groupBy($column)->havingRaw('count(*) > 1')->get()->count();
    }

    private function authorIdFor(?string $name): ?int
    {
        return $this->catalogueIdFor('authors', $name, 'author');
    }

    private function publisherIdFor(?string $name): ?int
    {
        return $this->catalogueIdFor('publishers', $name, 'publisher');
    }

    /** Finds the catalogue row for a free-text name, creating it if needed. */
    private function catalogueIdFor(string $table, ?string $name, string $fallbackSlug): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        if ($id = DB::table($table)->where('name', $name)->value('id')) {
            return $id;
        }

        $base = Str::slug($name) ?: $fallbackSlug;
        $slug = $base;
        for ($suffix = 2; DB::table($table)->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return DB::table($table)->insertGetId(['slug' => $slug, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }
};
