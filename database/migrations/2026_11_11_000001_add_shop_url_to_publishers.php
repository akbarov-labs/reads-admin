<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where readers can buy straight from the publisher: its shop channel on
     * Telegram, its Uzum Market page or its own online shop. Shown on the
     * publisher's page, since the official shop is often the cheapest.
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->string('shop_url')->nullable()->after('order_contact');
        });

        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->string('shop_url')->nullable()->after('order_contact');
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn('shop_url');
        });

        Schema::table('publisher_suggestions', function (Blueprint $table) {
            $table->dropColumn('shop_url');
        });
    }
};
