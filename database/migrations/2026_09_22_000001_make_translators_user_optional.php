<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A publisher adding a book they published needs to credit a translator
     * who has no profile here yet — nothing else in the panel lets a
     * non-admin provision a login, and it shouldn't for a name typed into a
     * book form. `user_id` becomes optional so that name can be saved as a
     * bare "shell" translator row (name + generated slug, no account), the
     * same way a publisher row can exist before anyone signs in to it. An
     * admin gives it a login later from the Translators resource, exactly
     * as they already do for an unclaimed publisher.
     */
    public function up(): void
    {
        // MySQL will not alter a column out from under a foreign key, so the
        // constraint comes off first and goes back on with the new
        // behaviour. The unique index on user_id is untouched by either
        // step — SQLite and MySQL both allow more than one NULL in it.
        Schema::table('translators', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('translators', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Rolling back means making the column NOT NULL again, which is only
        // possible if nothing is relying on the new freedom. Refusing loudly
        // beats silently deleting translator profiles an admin cannot get
        // back — the operator decides whether to attach an account to each
        // orphan or drop it.
        $orphans = DB::table('translators')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot reverse this migration: {$orphans} translator(s) have no account. "
                .'Attach a user to each, or delete them, then roll back again.'
            );
        }

        Schema::table('translators', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('translators', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
