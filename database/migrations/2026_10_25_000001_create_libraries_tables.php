<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libraries (kutubxonalar) a reader can borrow from, and which books each
     * one holds. A holding is per book, the work: a reader asks whether
     * "O'tkan kunlar" is there, and most libraries' lists carry no ISBN to
     * tell one printing from another. The edition is kept when it is known.
     */
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('description')->nullable();
            $table->string('region');
            $table->string('district')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->json('hours')->nullable();
            $table->json('membership')->nullable();
            $table->string('phone')->nullable();
            $table->string('telegram')->nullable();
            $table->string('instagram')->nullable();
            $table->string('website_url')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('holdings_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'region']);
        });

        Schema::create('library_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('copies_total')->default(1);
            $table->unsignedSmallInteger('copies_available')->default(1);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['library_id', 'book_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_holdings');
        Schema::dropIfExists('libraries');
    }
};
