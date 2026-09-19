<?php

use App\Enums\ModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A taqrizchi can now add a book in order to review it, which means the
     * catalogue accepts rows from someone who does not own the work. Those
     * wait for an admin.
     *
     * The default is Approved, not Pending, and that is deliberate: every
     * book that exists today was entered by a translator or an admin through
     * the panel and is already live. Defaulting to Pending would silently
     * unpublish the entire catalogue on deploy, and would also put every
     * future translator-entered book into a queue nobody asked for. The
     * taqrizchi flow sets Pending explicitly instead — the narrow path opts
     * in, rather than the whole table opting out.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('status')->default(ModerationStatus::Approved->value)->after('sort_order');
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
