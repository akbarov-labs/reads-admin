<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the store had the book when its page was last read, and how
     * many copies it said were left when it says so (Asaxiy does only for
     * the last few). Null is "the page didn't say". Read with fetched_at:
     * the book page shows a store only while that read is recent.
     */
    public function up(): void
    {
        Schema::table('store_listings', function (Blueprint $table) {
            $table->boolean('in_stock')->nullable()->after('price');
            $table->unsignedSmallInteger('stock_left')->nullable()->after('in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('store_listings', function (Blueprint $table) {
            $table->dropColumn(['in_stock', 'stock_left']);
        });
    }
};
