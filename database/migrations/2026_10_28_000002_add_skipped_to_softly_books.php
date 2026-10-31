<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A title an admin said is not a book to add (a magazine, a notebook, a
     * book they took back out). It is never created again, and leaves the
     * review list; the sync still links it if the catalogue gets the book.
     */
    public function up(): void
    {
        Schema::table('softly_books', function (Blueprint $table) {
            $table->boolean('skipped')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('softly_books', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
};
