<?php

use App\Support\Asaxiy\AsaxiyDescription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Asaxiy descriptions were imported from a workbook that had flattened
     * each one to a single line, so the book page showed one wall of text,
     * often opening with the shop's "… PREMYERA … sotuvda!". The importer
     * now tidies them (AsaxiyDescription); this applies the same to what is
     * already here.
     *
     * Only an imported edition's text that is still one line is touched:
     * one with a line break was written or fixed by hand. A book's own
     * description is always an admin's and is left alone.
     *
     * updated_at is left alone, as with the apostrophe cleanup: the bot is
     * not sent ten thousand rewrites, and the site picks the text up when
     * its cache expires.
     */
    public function up(): void
    {
        DB::table('editions')
            ->where('source_url', 'like', '%asaxiy.uz%')
            ->whereNotNull('description')
            ->where('description', 'not like', "%\n%")
            ->select(['id', 'description'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $tidy = AsaxiyDescription::tidy($row->description);

                    if ($tidy !== null && $tidy !== $row->description) {
                        DB::table('editions')->where('id', $row->id)->update(['description' => $tidy]);
                    }
                }
            });
    }

    /** The one-line text is not worth restoring. */
    public function down(): void {}
};
