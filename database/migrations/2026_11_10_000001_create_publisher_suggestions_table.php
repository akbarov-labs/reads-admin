<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Details found for a publisher (its Telegram channel, contacts, an About
 * text, the channel's photo) that wait for an admin before they reach the
 * publisher's page. One row per publisher: loading a newer file refreshes
 * a row still waiting, and leaves one already decided alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publisher_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('telegram_channel', 64)->nullable();
            $table->unsignedInteger('subscribers')->nullable();
            $table->string('order_contact', 64)->nullable();
            $table->string('website_url')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('instagram', 64)->nullable();
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('established_year')->nullable();
            $table->json('bio')->nullable();
            $table->string('logo_path')->nullable();
            // How sure the search was, and why: for the admin, never shown on the site.
            $table->string('confidence', 8)->nullable();
            $table->text('evidence')->nullable();
            $table->text('other_channels')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_suggestions');
    }
};
