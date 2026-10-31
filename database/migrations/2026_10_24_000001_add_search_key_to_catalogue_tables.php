<?php

use App\Support\SearchKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['books', 'authors', 'author_aliases', 'publishers', 'translators'];

    /**
     * One searchable spelling per row (see App\Support\SearchKeys), filled
     * in for what is already here. Unindexed: search matches the start of
     * any word, which an index cannot serve, and a scan of the whole
     * catalogue takes milliseconds.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('search_key', 1024)->nullable();
            });
        }

        SearchKeys::rebuild();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('search_key');
            });
        }
    }
};
