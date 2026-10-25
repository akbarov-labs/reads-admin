<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A library's Excel list on its way to library_holdings. Every row is
     * matched to a book here first and the owner confirms what the matcher
     * was unsure of; nothing reaches the holdings until they apply it.
     */
    public function up(): void
    {
        Schema::create('library_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('status')->default('review');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('library_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('title', 500)->nullable();
            $table->string('author', 500)->nullable();
            $table->string('isbn', 32)->nullable();
            $table->unsignedSmallInteger('copies')->nullable();
            $table->unsignedSmallInteger('available')->nullable();
            $table->string('match');
            $table->json('candidate_ids')->nullable();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision')->default('pending');
            $table->timestamps();

            $table->index(['library_import_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_import_rows');
        Schema::dropIfExists('library_imports');
    }
};
