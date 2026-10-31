<?php

namespace App\Support;

use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The `search_key` column: each searchable row's names and titles run
 * through UzbekText::searchKey(), so a query matches whatever alphabet,
 * case or apostrophe either side was typed in.
 *
 * Models keep their own key current on save (Concerns\HasSearchKey). A
 * query-builder update skips that, so after a bulk import, rebuild().
 */
final class SearchKeys
{
    /** @var array<class-string<Model>, list<string>> The columns each key is built from. */
    public const SOURCES = [
        Book::class => ['uzbek_title', 'original_title'],
        Author::class => ['name', 'original_name'],
        AuthorAlias::class => ['name'],
        Publisher::class => ['name'],
        Translator::class => ['name'],
    ];

    /** A query this short matches half the catalogue; it is not a search yet. */
    public const MIN_LENGTH = 2;

    /** Enough for a title and an author's name; anything longer is not typed by hand. */
    private const MAX_TERMS = 6;

    public static function of(Model $model): ?string
    {
        return UzbekText::searchKey(...array_map(fn (string $column) => $model->getAttribute($column), self::SOURCES[$model::class]));
    }

    /**
     * Recompute every key, in batches, without touching updated_at: the
     * bot syncs on that, and nothing it sends has changed.
     *
     * @return array<string, int> table => rows changed
     */
    public static function rebuild(): array
    {
        $changed = [];

        foreach (self::SOURCES as $class => $columns) {
            $table = (new $class)->getTable();
            $changed[$table] = 0;

            DB::table($table)->select(['id', 'search_key', ...$columns])->orderBy('id')
                ->chunk(1000, function ($rows) use ($table, $columns, &$changed) {
                    foreach ($rows as $row) {
                        $key = UzbekText::searchKey(...array_map(fn (string $column) => $row->{$column}, $columns));

                        if ($key !== $row->search_key) {
                            DB::table($table)->where('id', $row->id)->update(['search_key' => $key]);
                            $changed[$table]++;
                        }
                    }
                });
        }

        return $changed;
    }

    /**
     * The words to look for, as searchKey() spells them; empty when the
     * query is too short to search.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $key = UzbekText::searchKey($query) ?? '';

        if (mb_strlen($key) < self::MIN_LENGTH) {
            return [];
        }

        return array_slice(array_values(array_unique(explode(' ', $key))), 0, self::MAX_TERMS);
    }

    /**
     * Every term starts a word of the key, in any order: "kunlar otkan"
     * and "o'tk" both find "otkan kunlar"; "unlar" does not, which keeps
     * a two-letter prefix from matching the inside of every word.
     *
     * @param  list<string>  $terms
     */
    public static function match(Builder $query, string $column, array $terms): void
    {
        foreach ($terms as $term) {
            $escaped = self::escapeLike($term);
            $query->where(fn (Builder $word) => $word->where($column, 'like', "{$escaped}%")->orWhere($column, 'like', "% {$escaped}%"));
        }
    }

    /**
     * The key typed in full first, then one that starts with the query,
     * then the rest.
     *
     * @param  list<string>  $terms
     */
    public static function rank(Builder $query, string $column, array $terms): void
    {
        $phrase = implode(' ', $terms);

        $query->orderByRaw(
            "case when {$column} = ? then 0 when {$column} like ? then 1 else 2 end",
            [$phrase, self::escapeLike($phrase).'%'],
        );
    }

    private static function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }
}
