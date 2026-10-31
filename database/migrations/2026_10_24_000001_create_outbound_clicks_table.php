<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readers the site sent somewhere else: a store's search for a book, so
     * far. One row per click, so the numbers shown to a store or a publisher
     * can be counted any way they ask for.
     *
     * No address is kept. visitor_hash is a keyed hash of the address, the
     * browser and the day, enough to count a visitor once a day and nothing
     * more: tomorrow the same person hashes to something else.
     */
    public function up(): void
    {
        Schema::create('outbound_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            // A store key ("asaxiy", "book.uz") or, later, a publisher's slug.
            $table->string('target', 64);
            $table->string('locale', 5)->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['book_id', 'created_at']);
            $table->index(['target', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_clicks');
    }
};
