<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What online stores say about the books they sell, one row per product
 * page (stores:qamar). A listing whose ISBN is in the catalogue fills that
 * edition's blanks (its cover, its page count) and notes where the store
 * disagrees; one whose ISBN is not is a book the catalogue doesn't have yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_listings', function (Blueprint $table) {
            $table->id();
            $table->string('store', 32);
            $table->string('url')->unique();
            $table->string('isbn', 13)->nullable()->index();
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->string('publisher')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            // The sitemap's date for the page, to read it again only once it changes.
            $table->timestamp('lastmod')->nullable();
            $table->timestamp('fetched_at')->nullable();
            // When the listing was last compared with its edition, what that
            // filled (field names) and where the two disagree (field =>
            // [catalogue, store]).
            $table->timestamp('applied_at')->nullable();
            $table->json('filled')->nullable();
            $table->json('differences')->nullable();
            $table->timestamps();

            $table->index(['store', 'edition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_listings');
    }
};
