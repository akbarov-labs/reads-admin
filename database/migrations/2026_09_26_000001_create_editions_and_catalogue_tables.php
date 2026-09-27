<?php

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A `books` row stops being "one translator's edition" and becomes the
     * work itself; each printing of it — a publisher, an ISBN, a year, the
     * people who translated it — is an `editions` row. One work, many
     * editions: *Katta o'yin* from two publishers under two ISBNs, each with
     * its own translators, is one book with two editions rather than two
     * unrelated books. docs/DATA-MODEL.md has the full picture.
     *
     * This migration only adds structure. Moving the existing data across
     * and dropping the old columns is the next one, so each can be reasoned
     * about (and rolled back) on its own.
     */
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            // Where the category came from (e.g. an Asaxiy listing path), so
            // a later sync can find it again after an admin renames it.
            $table->string('source_path')->nullable()->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->text('description')->nullable()->after('source_language');
            $table->foreignId('series_id')->nullable()->after('description')->constrained('series')->nullOnDelete();
            // Decimal, not integer: "Part 1.5" novellas and "0" prequels exist.
            $table->decimal('series_position', 5, 1)->nullable()->after('series_id');
        });

        Schema::table('authors', function (Blueprint $table) {
            // The spelling in the author's own script ("Абдулла Қодирий"),
            // kept so editions in other languages can be matched to them.
            $table->string('original_name')->nullable()->after('name');
        });

        Schema::create('book_author', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['book_id', 'author_id']);
        });

        Schema::create('book_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            // The book's genre, as opposed to the other shelves it is also on.
            $table->boolean('is_primary')->default(false);
            $table->unique(['book_id', 'category_id']);
        });

        // "Readers of this also liked". Directed, because the source's
        // recommendations are: A suggesting B says nothing about B's page.
        Schema::create('book_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_book_id')->constrained('books')->cascadeOnDelete();
            $table->string('source', 32);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['book_id', 'related_book_id', 'source']);
        });

        Schema::create('editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();

            // As printed on this edition's cover; null means "same as the
            // book". A Russian edition of an Uzbek-titled work is where the
            // two differ.
            $table->string('title')->nullable();
            // Unique where known. Two rows claiming one ISBN is a duplicate,
            // not two editions.
            $table->string('isbn', 13)->nullable()->unique();
            $table->string('language')->nullable();
            $table->string('script')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('cover_type')->nullable();
            $table->string('cover_material')->nullable();
            $table->string('paper_format')->nullable();
            $table->string('page_surface')->nullable();
            $table->string('print_country')->nullable();
            $table->string('weight')->nullable();
            $table->string('dimensions')->nullable();
            $table->unsignedSmallInteger('volume_count')->nullable();
            $table->unsignedSmallInteger('volume_part')->nullable();
            $table->string('cover_path')->nullable();
            $table->longText('description')->nullable();

            // A translator's sample of their own prose, so it belongs to the
            // edition they translated, not to the work.
            $table->string('excerpt_source_language')->nullable();
            $table->text('excerpt_source_text')->nullable();
            $table->text('excerpt_translated_text')->nullable();

            $table->string('status')->default(ModerationStatus::Approved->value);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Provenance for imported rows, so they can be refreshed later.
            $table->string('source_url')->nullable();
            $table->string('source_article', 32)->nullable()->unique();

            $table->timestamps();

            $table->index(['book_id', 'status']);
            $table->index('status');
        });

        Schema::create('edition_translator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('translator_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default(BookRole::Translator->value);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['edition_id', 'translator_id', 'role']);
        });

        Schema::create('edition_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_images');
        Schema::dropIfExists('edition_translator');
        Schema::dropIfExists('editions');
        Schema::dropIfExists('book_relations');
        Schema::dropIfExists('book_category');
        Schema::dropIfExists('book_author');

        Schema::table('authors', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['description', 'series_position']);
        });

        Schema::dropIfExists('categories');
        Schema::dropIfExists('series');
    }
};
