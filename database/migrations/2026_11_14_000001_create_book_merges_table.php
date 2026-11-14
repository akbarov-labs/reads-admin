<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is left of a book merged into another (BookMerger), the way
     * author_aliases is for authors:
     *
     * - `merged_book_id`, so /api/books/{old id} answers with the kept book
     *   and the site redirects the old address instead of losing it;
     * - `merge_key` (first author + title, as CatalogueRecords::bookKey
     *   writes it), so an import or the bot that sends a new ISBN under the
     *   old title joins the kept book instead of making the duplicate again;
     * - `snapshot`, everything the merge moved or dropped, so
     *   `books:unmerge` can put it back.
     *
     * No foreign key on merged_book_id: that book no longer exists.
     */
    public function up(): void
    {
        Schema::create('book_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merged_book_id')->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('merged_title');
            $table->string('merge_key')->index();
            $table->json('snapshot');
            $table->timestamps();

            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_merges');
    }
};
