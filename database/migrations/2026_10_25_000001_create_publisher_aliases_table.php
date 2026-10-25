<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is left of a publisher merged into another (PublisherMerger), as
     * author_aliases is for authors: the name it went by, so an import or
     * the bot that still spells it that way finds the kept publisher, and
     * its slug, so an old link to its page still arrives.
     */
    public function up(): void
    {
        Schema::create('publisher_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('slug')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_aliases');
    }
};
