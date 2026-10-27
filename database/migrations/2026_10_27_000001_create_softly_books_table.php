<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libraries that run on Softly (library.softly.uz, the system behind
     * kutubxonam.uz) and the books Softly lists, as the last sync saw them.
     *
     * A library is tied to Softly by its location id. softly_books keeps
     * each Softly book once with the catalogue book it was matched to, so a
     * title is matched the first time it is seen, not on every sync, and the
     * holdings of every linked library are worked out from it.
     */
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->unsignedInteger('softly_location_id')->nullable()->unique();
        });

        Schema::create('softly_books', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('softly_id')->unique();
            $table->string('name', 500);
            $table->string('author', 500)->nullable();
            // [{id, busy, locationId}], one per copy.
            $table->json('stocks');
            $table->timestamp('softly_updated_at')->nullable()->index();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match', 16);
            $table->json('candidate_ids')->nullable();
            // The title and author the match was made on; a change rematches.
            $table->string('matched_on', 1001)->nullable();
            $table->timestamps();

            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('softly_books');

        Schema::table('libraries', function (Blueprint $table) {
            $table->dropUnique(['softly_location_id']);
            $table->dropColumn('softly_location_id');
        });
    }
};
