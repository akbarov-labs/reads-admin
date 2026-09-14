<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->unique();
            $table->json('bio')->nullable();
            $table->json('nationality')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedSmallInteger('death_year')->nullable();
            $table->string('portrait_path')->nullable();
            $table->string('website_url')->nullable();
            $table->timestamps();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->after('translator_id')->constrained()->nullOnDelete();
            $table->string('author')->nullable()->change();
            $table->index('author');
        });

        $legacyAuthors = \Illuminate\Support\Facades\DB::table('books')
            ->whereNotNull('author')
            ->where('author', '<>', '')
            ->select('author')
            ->distinct()
            ->pluck('author');

        foreach ($legacyAuthors as $name) {
            $slug = \Illuminate\Support\Str::slug($name) ?: 'author';
            $baseSlug = $slug;
            $suffix = 2;
            while (\Illuminate\Support\Facades\DB::table('authors')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            \Illuminate\Support\Facades\DB::table('authors')->insert([
                'slug' => $slug,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\DB::table('books')
            ->whereNotNull('author')
            ->where('author', '<>', '')
            ->orderBy('id')
            ->eachById(function (object $book): void {
                $authorId = \Illuminate\Support\Facades\DB::table('authors')
                    ->where('name', $book->author)
                    ->value('id');

                \Illuminate\Support\Facades\DB::table('books')
                    ->where('id', $book->id)
                    ->update(['author_id' => $authorId]);
            });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
            $table->dropIndex(['author']);
            $table->dropColumn('author_id');
        });

        Schema::dropIfExists('authors');
    }
};