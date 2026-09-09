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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translator_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            // Snapshot of the pair at submission time, not a foreign key —
            // the translator's offered pairs can change later, but the
            // inquiry should keep showing what the client actually asked for.
            $table->string('from_language');
            $table->string('to_language');
            $table->string('project_type');
            $table->unsignedInteger('word_count')->nullable();
            $table->string('budget_range')->nullable();
            $table->date('deadline')->nullable();
            $table->text('message');
            $table->string('status')->default('new');
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->index(['translator_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
