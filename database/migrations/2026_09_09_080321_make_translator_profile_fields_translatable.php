<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Wrap existing plain-string content as its "uz" translation while
        // the columns are still plain VARCHAR/TEXT (so it stays valid to
        // store), *before* changing the column type to JSON — MySQL
        // validates existing content against the new type on ALTER.
        foreach (DB::table('translators')->select('id', 'bio', 'location')->get() as $row) {
            DB::table('translators')->where('id', $row->id)->update([
                'bio' => $row->bio !== null ? json_encode(['uz' => $row->bio]) : null,
                'location' => $row->location !== null ? json_encode(['uz' => $row->location]) : null,
            ]);
        }

        Schema::table('translators', function (Blueprint $table) {
            $table->json('bio')->nullable()->change();
            $table->json('location')->nullable()->change();
            $table->json('title')->nullable()->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translators', function (Blueprint $table) {
            $table->dropColumn('title');
            $table->text('bio')->nullable()->change();
            $table->string('location')->nullable()->change();
        });

        foreach (DB::table('translators')->select('id', 'bio', 'location')->get() as $row) {
            DB::table('translators')->where('id', $row->id)->update([
                'bio' => $row->bio ? (json_decode($row->bio, true)['uz'] ?? null) : null,
                'location' => $row->location ? (json_decode($row->location, true)['uz'] ?? null) : null,
            ]);
        }
    }
};
