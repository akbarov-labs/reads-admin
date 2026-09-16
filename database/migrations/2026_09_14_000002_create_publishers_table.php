<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->unique();
            $table->json('bio')->nullable();
            $table->json('country')->nullable();
            $table->unsignedSmallInteger('established_year')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('website_url')->nullable();
            $table->timestamps();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('publisher_id')->nullable()->after('author_id')->constrained()->nullOnDelete();
            $table->string('publisher')->nullable()->change();
            $table->index('publisher');
        });

        DB::table('books')
            ->whereNotNull('publisher')
            ->where('publisher', '<>', '')
            ->select('publisher')
            ->distinct()
            ->pluck('publisher')
            ->each(function (string $name): void {
                $slug = Str::slug($name) ?: 'publisher';
                $baseSlug = $slug;
                $suffix = 2;
                while (DB::table('publishers')->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix++;
                }

                DB::table('publishers')->insert([
                    'slug' => $slug,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('books')
            ->whereNotNull('publisher')
            ->where('publisher', '<>', '')
            ->orderBy('id')
            ->eachById(function (object $book): void {
                $publisherId = DB::table('publishers')
                    ->where('name', $book->publisher)
                    ->value('id');

                DB::table('books')->where('id', $book->id)->update([
                    'publisher_id' => $publisherId,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['publisher_id']);
            $table->dropIndex(['publisher']);
            $table->dropColumn('publisher_id');
        });

        Schema::dropIfExists('publishers');
    }
};