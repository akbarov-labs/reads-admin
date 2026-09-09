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
        Schema::create('translators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('avatar_path')->nullable();
            $table->string('location')->nullable();
            $table->text('bio')->nullable();
            $table->string('telegram')->nullable();
            $table->string('instagram')->nullable();
            $table->unsignedSmallInteger('years_active')->default(0);
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translators');
    }
};
