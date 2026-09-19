<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publishers get their own logins, so they can keep their details current
     * and add the books they publish instead of waiting for an admin to type
     * them in.
     *
     * Nullable, unlike translators.user_id: every publisher row today was
     * derived from the free-text `publisher` column on books during the
     * catalogue backfill, and nobody has claimed those. A publisher without
     * an account is a perfectly good catalogue entry — it simply has nobody
     * signing in to maintain it.
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained()
                // Deleting the login must not delete the publisher: the books
                // crediting them would lose their catalogue row with it.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
