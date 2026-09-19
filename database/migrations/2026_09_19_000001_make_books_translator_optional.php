<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until now a book could only exist inside a translator's profile:
     * `translator_id` was required, so "a book nobody has translated yet" was
     * not representable. A taqrizchi writing about a book we do not hold has
     * to be able to add it, so the relation becomes optional.
     *
     * The delete behaviour changes with it, and that part matters more than
     * the nullability. The original constraint cascaded: deleting a translator
     * deleted their books. Once taqrizlar hang off books, that cascade would
     * reach through and destroy other people's reviews as a side effect of
     * removing one translator. Orphaning the book is the correct outcome —
     * the book still exists in the world, we simply no longer know who
     * translated it.
     */
    public function up(): void
    {
        // MySQL will not alter a column out from under a foreign key, so the
        // constraint comes off first and goes back on with the new behaviour.
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['translator_id']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('translator_id')->nullable()->change();

            $table->foreign('translator_id')
                ->references('id')
                ->on('translators')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Rolling back means making the column NOT NULL again, which is only
        // possible if nothing is relying on the new freedom. Refusing loudly
        // beats silently deleting books an admin cannot get back — the
        // operator decides whether to reassign them or drop them.
        $orphans = DB::table('books')->whereNull('translator_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot reverse this migration: {$orphans} book(s) have no translator. "
                .'Assign a translator to each, or delete them, then roll back again.'
            );
        }

        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['translator_id']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('translator_id')->nullable(false)->change();

            $table->foreign('translator_id')
                ->references('id')
                ->on('translators')
                ->cascadeOnDelete();
        });
    }
};
