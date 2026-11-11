<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Researched facts about an author or a translator, waiting for an
     * admin: one row per person, so loading the file again refreshes it.
     */
    public function up(): void
    {
        Schema::create('profile_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('original_name')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedSmallInteger('death_year')->nullable();
            $table->json('nationality')->nullable();
            $table->json('title')->nullable();
            $table->json('location')->nullable();
            $table->json('bio')->nullable();
            $table->json('sources')->nullable();
            $table->string('confidence', 16)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_suggestions');
    }
};
