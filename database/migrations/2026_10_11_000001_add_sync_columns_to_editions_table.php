<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookkeeping for the Kitoblarim bot sync (docs/DATA-MODEL.md).
     *
     * `sync_hash` is the hash of what the bot and this app last agreed an
     * edition looks like. The pull feed leaves out any edition whose
     * current hash still equals it, so the bot is not handed back what it
     * pushed a moment ago. Nothing else reads these columns.
     */
    public function up(): void
    {
        Schema::table('editions', function (Blueprint $table) {
            $table->string('sync_origin', 16)->nullable()->after('source_article');
            $table->string('sync_hash', 40)->nullable()->after('sync_origin');
            $table->timestamp('synced_at')->nullable()->after('sync_hash');

            // The pull feed pages through editions in (updated_at, id) order.
            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('editions', function (Blueprint $table) {
            $table->dropIndex(['updated_at', 'id']);
            $table->dropColumn(['sync_origin', 'sync_hash', 'synced_at']);
        });
    }
};
