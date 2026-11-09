<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a reader reaches a publisher besides its Telegram channel: shown on
     * the publisher's page on the site.
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('order_contact');
            $table->string('instagram', 64)->nullable()->after('phone');
            $table->string('address')->nullable()->after('instagram');
        });
    }

    public function down(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn(['phone', 'instagram', 'address']);
        });
    }
};
