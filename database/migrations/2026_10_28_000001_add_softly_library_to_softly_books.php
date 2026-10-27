<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Softly library listed each book. Softly answers for one library
     * at a time, so every book is kept per library, with its own place to
     * stop at on quick runs. Every row so far came from Mehr (1).
     */
    public function up(): void
    {
        Schema::table('softly_books', function (Blueprint $table) {
            $table->unsignedInteger('softly_library')->default(1);
        });

        Schema::table('softly_books', function (Blueprint $table) {
            $table->dropUnique(['softly_id']);
            $table->unique(['softly_library', 'softly_id']);
        });
    }

    public function down(): void
    {
        // Back to Mehr only, where a Softly id is unique on its own.
        DB::table('softly_books')->where('softly_library', '!=', 1)->delete();

        Schema::table('softly_books', function (Blueprint $table) {
            $table->dropUnique(['softly_library', 'softly_id']);
        });

        Schema::table('softly_books', function (Blueprint $table) {
            $table->dropColumn('softly_library');
            $table->unique('softly_id');
        });
    }
};
