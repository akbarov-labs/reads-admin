<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines from a book, as an editor copied them: shown on the book's page
     * and, one a day, on the home page. A quote from a translation names the
     * edition, since another translator words the same line differently.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->unsignedSmallInteger('page')->nullable();
            $table->string('status', 16)->default('approved');
            $table->timestamps();

            $table->index(['book_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
