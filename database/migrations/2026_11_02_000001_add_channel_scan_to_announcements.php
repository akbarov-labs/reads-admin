<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publishers' channels are read every hour (announcements:scan), and
     * posts that look like a new book or a pre-order become drafts on their
     * own, beside the links an admin pastes.
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            // The newest post already looked at, so a scan reads only what
            // came after it; and when the channel was last read.
            $table->unsignedBigInteger('telegram_seen_post_id')->nullable()->after('order_contact');
            $table->timestamp('telegram_scanned_at')->nullable()->after('telegram_seen_post_id');
        });

        Schema::table('announcements', function (Blueprint $table) {
            // Made by the scan rather than from a pasted link.
            $table->boolean('found_by_scan')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('found_by_scan');
        });

        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn(['telegram_seen_post_id', 'telegram_scanned_at']);
        });
    }
};
