<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The site's own statistics next to its store clicks: which books are
     * read about, and what readers look for, found or not, without waiting
     * days for Search Console.
     */
    public function up(): void
    {
        // A counter per book, Tashkent day and site language rather than a
        // row per view: views outnumber clicks many times over, and the
        // dashboard only ever asks how many.
        Schema::create('book_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('locale', 5);
            $table->unsignedInteger('views')->default(0);

            $table->unique(['book_id', 'day', 'locale']);
            $table->index('day');
        });

        // A row per search: the words are the point, and the same words
        // asked by different people are counted apart.
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            // As typed, tidied (UzbekText::clean), for showing.
            $table->string('query', 100);
            // UzbekText::searchKey: "Oʻtkan kunlar", "ЎТКАН КУНЛАР" and
            // "otkan kunlar" are one query on the dashboard.
            $table->string('query_key', 100);
            // How many books the listing found; null for a pick from the
            // search box, which searches more than books.
            $table->unsignedInteger('results')->nullable();
            // "page": the books listing (Enter, or no JavaScript).
            // "box": a suggestion picked as the reader typed.
            $table->string('source', 8);
            // For "box": the page the reader picked, e.g. /book/12.
            $table->string('picked', 200)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['query_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
        Schema::dropIfExists('book_views');
    }
};
