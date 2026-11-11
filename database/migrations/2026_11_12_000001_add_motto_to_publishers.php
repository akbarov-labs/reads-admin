<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The publisher's own slogan, word for word, by locale. Kept apart from
     * the About text so the site can show it on its own under the name.
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->json('motto')->nullable()->after('bio');
        });

        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->json('motto')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn('motto');
        });

        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->dropColumn('motto');
        });
    }
};
