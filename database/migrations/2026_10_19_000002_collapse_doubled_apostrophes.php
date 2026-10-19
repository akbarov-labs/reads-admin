<?php

use App\Support\UzbekText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Where a title or a name is shown as typed. */
    private const COLUMNS = [
        'books' => ['uzbek_title', 'original_title'],
        'editions' => ['title'],
        'authors' => ['name', 'original_name'],
        'translators' => ['name'],
        'publishers' => ['name'],
        'series' => ['name'],
    ];

    /** A name that must stay unique: one already spelt right means two records to merge, not a rename. */
    private const UNIQUE = ['authors.name', 'publishers.name'];

    /**
     * "mo''jiza" was stored as typed before UzbekText learnt that a doubled
     * apostrophe inside a word is one. The same rule, applied to what is
     * already here.
     *
     * updated_at is left alone, as with the year cleanup: the bot is not
     * sent this, and its next push of the same title is cleaned on arrival.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)
                    ->where($column, 'like', "%''%")
                    ->orderBy('id')
                    ->get(['id', $column])
                    ->each(function (object $row) use ($table, $column) {
                        $fixed = UzbekText::normalizeApostrophes($row->{$column});

                        if ($fixed === $row->{$column}) {
                            return;
                        }

                        if (in_array("{$table}.{$column}", self::UNIQUE, true)
                            && DB::table($table)->where($column, $fixed)->exists()) {
                            return;
                        }

                        DB::table($table)->where('id', $row->id)->update([$column => $fixed]);
                    });
            }
        }
    }

    /** One apostrophe is the right spelling; there is nothing to restore. */
    public function down(): void {}
};
