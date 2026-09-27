<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL gives every foreign key an index of its own; SQLite, which
     * production runs on, does not. Without these, counting each author's
     * books scans all of book_author once per author — /api/authors took
     * 33 s against the Asaxiy catalogue and timed out as a 502.
     *
     * Each index is added only where the column is not already the leading
     * column of one, so MySQL does not end up with duplicates of the indexes
     * its foreign keys already made.
     *
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        'book_author' => ['author_id'],
        'book_category' => ['category_id'],
        'book_relations' => ['related_book_id'],
        'books' => ['series_id'],
        'editions' => ['publisher_id'],
        'edition_translator' => ['translator_id'],
        'edition_images' => ['edition_id'],
        'categories' => ['parent_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (! $this->leadsAnIndex($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($column, $this->name($table, $column)));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasIndex($table, $this->name($table, $column))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $column)));
                }
            }
        }
    }

    private function leadsAnIndex(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function name(string $table, string $column): string
    {
        return "{$table}_{$column}_lookup";
    }
};
