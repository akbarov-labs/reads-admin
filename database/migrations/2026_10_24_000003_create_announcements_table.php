<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publishers announce new books and discounted pre-orders on their
     * public Telegram channels. An admin pastes a post's link, reviews the
     * draft made from it, and approves it onto the site, where an order
     * button opens the publisher's Telegram contact (counted as an outbound
     * click of kind publisher_order).
     */
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table) {
            // Usernames without the @: the channel posts are read from, and
            // the account readers write to order.
            $table->string('telegram_channel', 64)->nullable()->after('website_url');
            $table->string('order_contact', 64)->nullable()->after('telegram_channel');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained()->cascadeOnDelete();
            // The catalogue's book, once it has one.
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            // https://t.me/<channel>/<id>: one draft per post.
            $table->string('source_url')->unique();
            $table->timestamp('posted_at')->nullable();
            // The post as it was, for the reviewer to check the fields against.
            $table->text('post_text')->nullable();
            $table->string('title');
            $table->string('author_name')->nullable();
            $table->string('translator_name')->nullable();
            // Whole so'm.
            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('preorder_price')->nullable();
            // The last day pre-orders are taken; the announcement leaves the
            // site after it.
            $table->date('preorder_deadline')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->index(['status', 'preorder_deadline']);
        });

        Schema::table('outbound_clicks', function (Blueprint $table) {
            $table->foreignId('announcement_id')->nullable()->after('book_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_clicks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('announcement_id');
        });

        Schema::dropIfExists('announcements');

        Schema::table('publishers', function (Blueprint $table) {
            $table->dropColumn(['telegram_channel', 'order_contact']);
        });
    }
};
