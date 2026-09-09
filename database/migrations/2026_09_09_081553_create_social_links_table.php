<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translator_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('url');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['translator_id', 'platform']);
        });

        // Carry forward the old fixed telegram/instagram columns as rows in
        // the new generic table, storing them as full URLs to match how
        // every other platform is stored.
        foreach (DB::table('translators')->select('id', 'telegram', 'instagram')->get() as $row) {
            if (filled($row->telegram)) {
                DB::table('social_links')->insert([
                    'translator_id' => $row->id,
                    'platform' => 'telegram',
                    'url' => 'https://t.me/'.ltrim($row->telegram, '@'),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (filled($row->instagram)) {
                DB::table('social_links')->insert([
                    'translator_id' => $row->id,
                    'platform' => 'instagram',
                    'url' => 'https://instagram.com/'.ltrim($row->instagram, '@'),
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('translators', function (Blueprint $table) {
            $table->dropColumn(['telegram', 'instagram']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translators', function (Blueprint $table) {
            $table->string('telegram')->nullable();
            $table->string('instagram')->nullable();
        });

        foreach (DB::table('social_links')->whereIn('platform', ['telegram', 'instagram'])->get() as $link) {
            $handle = ltrim(parse_url($link->url, PHP_URL_PATH) ?? '', '/');

            DB::table('translators')->where('id', $link->translator_id)->update([
                $link->platform => $handle,
            ]);
        }

        Schema::dropIfExists('social_links');
    }
};
