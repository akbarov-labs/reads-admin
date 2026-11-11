<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a suggested About text came from: the pages the reviewer opens
     * to check it before publishing.
     */
    public function up(): void
    {
        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->json('about_sources')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->dropColumn('about_sources');
        });
    }
};
