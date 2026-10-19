<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is left of an author merged into another (AuthorMerger): the name
     * it went by, so an import or the bot that still spells it that way finds
     * the author it was merged into instead of making it again, and its slug,
     * so an old link to its page still arrives.
     */
    public function up(): void
    {
        Schema::create('author_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('slug')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_aliases');
    }
};
