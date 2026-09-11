<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-translator search-engine overrides. Every one of these is optional:
 * when a column is null the site falls back to a value derived from the
 * profile itself (see App\Support\TranslatorSeo), so a translator who never
 * touches SEO still gets a sensible, book-count-aware title/description
 * that updates itself whenever their profile or library changes.
 *
 * Only admins may write these — enforced in TranslatorObserver, not just by
 * hiding the fields in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translators', function (Blueprint $table) {
            // Translatable (uz/ru/en), same JSON shape as bio/location/title.
            $table->json('meta_title')->nullable()->after('title');
            $table->json('meta_description')->nullable()->after('meta_title');
            // Social-preview image. Separate from the avatar because the
            // ideal crop is 1200x630, not a circle.
            $table->string('og_image_path')->nullable()->after('meta_description');
            // Escape hatch for a profile that should stay out of search
            // results (draft, disputed, or at the translator's request).
            $table->boolean('seo_noindex')->default(false)->after('og_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('translators', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'og_image_path', 'seo_noindex']);
        });
    }
};
