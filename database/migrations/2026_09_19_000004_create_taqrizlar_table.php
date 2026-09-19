<?php

use App\Enums\TaqrizStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One taqrizchi's review of one book: the essay itself, an optional
     * video, and an optional score.
     *
     * The score is five separate aspects rather than one number so that two
     * reviews of the same book are comparable on the same axes. Each is
     * independently optional — a reviewer who only wants to rate the prose
     * can — and the overall figure is derived from whichever were filled in,
     * never stored. decimal(3,1) covers 1.0 to 10.0 in half steps.
     *
     * `score_translation` is the odd one out: a book with no translator has
     * no translation to rate, so the form hides it and it stays null. That is
     * why the average must divide by the number of non-null aspects and not
     * by five.
     */
    public function up(): void
    {
        Schema::create('taqrizlar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taqrizchi_id')->constrained('taqrizchilar')->cascadeOnDelete();
            $table->text('body');
            $table->string('youtube_url')->nullable();

            $table->decimal('score_plot', 3, 1)->nullable();
            $table->decimal('score_style', 3, 1)->nullable();
            $table->decimal('score_translation', 3, 1)->nullable();
            $table->decimal('score_cover', 3, 1)->nullable();
            $table->decimal('score_overall', 3, 1)->nullable();

            $table->string('status')->default(TaqrizStatus::Pending->value);
            $table->timestamps();

            // A reviewer gets one say per book. Revising means editing the
            // taqriz they already wrote, not adding a second one.
            $table->unique(['taqrizchi_id', 'book_id']);

            // The book page asks for "approved taqrizlar for this book" on
            // every render; this is that query.
            $table->index(['book_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taqrizlar');
    }
};
