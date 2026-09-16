<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores a photo for the book's author and a logo/image for the publisher,
     * so the public site can display rich visual cards for the Authors and
     * Publishers discovery sections.
     *
     * Both are optional: existing books stay valid without them, and the
     * frontend falls back to a stylised initials avatar when they are absent.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('author_image_path')->nullable()->after('author');
            $table->string('publisher_image_path')->nullable()->after('publisher');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['author_image_path', 'publisher_image_path']);
        });
    }
};
