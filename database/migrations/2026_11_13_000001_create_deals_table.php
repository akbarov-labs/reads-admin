<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discounts and contests readers can use today: a publisher's sale or
     * giveaway posted on its Telegram channel (drafted by the hourly scan or
     * pasted), or a shop's promotion an admin types in. Pending until an
     * admin has checked it against the source; gone from the site the day
     * after it ends (see Deal::scopeLive).
     */
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            // App\Enums\DealKind: discount | contest | event.
            $table->string('kind', 16)->default('discount');
            // Who runs it: a publisher in the catalogue, or else a shop by
            // name (Asaxiy, Qamar) that isn't one.
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shop_name', 64)->nullable();
            // A deal on one book in the catalogue, when it is one.
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // The conditions in a few lines: which books, minimum order, how to enter.
            $table->text('details')->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->string('promo_code', 32)->nullable();
            $table->date('starts_on')->nullable();
            // The last day it holds (a Tashkent day).
            $table->date('ends_on')->nullable();
            // Where a reader takes it up: the shop's page, or the post.
            $table->string('url', 512)->nullable();
            // https://t.me/<channel>/<id>: one draft per post.
            $table->string('source_url')->nullable()->unique();
            $table->timestamp('posted_at')->nullable();
            $table->text('post_text')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status', 16)->default('pending');
            $table->boolean('found_by_scan')->default(false);
            $table->timestamps();

            $table->index(['status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
