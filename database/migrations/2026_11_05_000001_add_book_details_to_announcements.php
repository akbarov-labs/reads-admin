<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publishing an announcement makes its book in the catalogue (see
     * App\Services\AnnouncementBook), so the details a post rarely carries
     * are typed while reviewing it. And one book can be ordered somewhere
     * other than its publisher's usual account.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Overrides the publisher's order account for this book only.
            $table->string('order_contact', 32)->nullable()->after('cover_path');
            $table->string('isbn', 32)->nullable()->after('order_contact');
            $table->unsignedSmallInteger('pages')->nullable()->after('isbn');
            $table->unsignedSmallInteger('year')->nullable()->after('pages');
            $table->text('description')->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['order_contact', 'isbn', 'pages', 'year', 'description']);
        });
    }
};
