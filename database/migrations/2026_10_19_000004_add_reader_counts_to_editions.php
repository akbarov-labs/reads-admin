<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * More of what the Kitoblarim bot's readers do with a book, per ISBN
     * (docs/DATA-MODEL.md, "Ratings and reviews from the bot"): how their
     * stars split from one to five, and how many want to read it, are
     * reading it and have read it. Counts only, never who.
     */
    public function up(): void
    {
        Schema::table('editions', function (Blueprint $table) {
            // [ones, twos, threes, fours, fives]; null until the bot sends it.
            $table->json('reader_rating_stars')->nullable()->after('reader_rating_total');
            $table->unsignedInteger('reader_want_count')->default(0)->after('reader_rating_stars');
            $table->unsignedInteger('reader_reading_count')->default(0)->after('reader_want_count');
            $table->unsignedInteger('reader_read_count')->default(0)->after('reader_reading_count');
        });
    }

    public function down(): void
    {
        Schema::table('editions', function (Blueprint $table) {
            $table->dropColumn(['reader_rating_stars', 'reader_want_count', 'reader_reading_count', 'reader_read_count']);
        });
    }
};
