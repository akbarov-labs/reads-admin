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
        Schema::create('language_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translator_id')->constrained()->cascadeOnDelete();
            $table->string('from_language');
            $table->string('to_language');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('language_pairs');
    }
};
