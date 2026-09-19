<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social links were a translator-only idea. Taqrizchilar get the same
     * profile furniture, so the table stops belonging to one owner type.
     *
     * The alternative — a nullable `taqrizchi_id` beside `translator_id` —
     * needs a constraint saying "exactly one of these is set" that no
     * database expresses comfortably, and grows a column per future owner.
     */
    public function up(): void
    {
        Schema::table('social_links', function (Blueprint $table) {
            $table->nullableMorphs('owner');
        });

        // Every existing row belongs to a translator by definition.
        DB::table('social_links')->update([
            'owner_type' => \App\Models\Translator::class,
        ]);
        DB::statement('UPDATE social_links SET owner_id = translator_id');

        Schema::table('social_links', function (Blueprint $table) {
            $table->dropForeign(['translator_id']);
            $table->dropUnique(['translator_id', 'platform']);
            $table->dropColumn('translator_id');

            // One link per platform per profile, as before — just scoped to
            // whichever kind of profile owns it.
            $table->unique(['owner_type', 'owner_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::table('social_links', function (Blueprint $table) {
            $table->foreignId('translator_id')->nullable()->after('id');
        });

        DB::statement(
            'UPDATE social_links SET translator_id = owner_id WHERE owner_type = ?',
            [\App\Models\Translator::class]
        );

        // Links owned by anything other than a translator cannot be
        // represented by the old schema and would become rows pointing at
        // nothing.
        DB::table('social_links')->whereNull('translator_id')->delete();

        Schema::table('social_links', function (Blueprint $table) {
            $table->dropUnique(['owner_type', 'owner_id', 'platform']);
            $table->dropMorphs('owner');

            $table->foreignId('translator_id')->nullable(false)->change();
            $table->foreign('translator_id')->references('id')->on('translators')->cascadeOnDelete();
            $table->unique(['translator_id', 'platform']);
        });
    }
};
