<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translator_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('translator');
            $table->string('uzbek_title');
            $table->string('original_title');
            $table->string('author');
            $table->string('source_language');
            $table->string('publisher')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('excerpt_source_language')->nullable();
            $table->text('excerpt_source_text')->nullable();
            $table->text('excerpt_translated_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
