<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A taqrizchi is a reviewer: they sign in, keep a public profile, and
     * write taqrizlar about books. Deliberately shaped like `translators` —
     * same login-account link, same slug-as-URL, same translatable profile
     * copy — because to a reader the two are the same kind of page, and the
     * public site renders them with the same components.
     *
     * What it does not copy: years_active/start_year and language pairs,
     * which describe a translation career rather than a reviewing one, and
     * the SEO override columns, which no one has asked to manage per
     * reviewer yet.
     */
    public function up(): void
    {
        Schema::create('taqrizchilar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('avatar_path')->nullable();
            $table->text('location')->nullable();
            $table->text('bio')->nullable();
            $table->text('title')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taqrizchilar');
    }
};
