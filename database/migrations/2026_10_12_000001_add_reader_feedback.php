<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What readers of the Kitoblarim bot think of a book (docs/DATA-MODEL.md,
     * "Ratings and reviews from the bot").
     *
     * Ratings arrive as a count and a total of stars per ISBN, with nothing
     * about who rated, so they live on the edition. Reviews are the ones their
     * writers chose to publish; each waits for a moderator here.
     */
    public function up(): void
    {
        Schema::table('editions', function (Blueprint $table) {
            $table->unsignedInteger('reader_rating_count')->default(0)->after('synced_at');
            $table->unsignedInteger('reader_rating_total')->default(0)->after('reader_rating_count');
        });

        Schema::create('reader_reviews', function (Blueprint $table) {
            $table->id();
            // The bot's id for the review, so a resend updates rather than duplicates.
            $table->string('external_id', 64)->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 60)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestamp('written_at');
            $table->timestamps();

            $table->index(['book_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reader_reviews');

        Schema::table('editions', function (Blueprint $table) {
            $table->dropColumn(['reader_rating_count', 'reader_rating_total']);
        });
    }
};
