<?php

use App\Models\Edition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Before the bot sync checked years, one pushed "9943" (the ISBN's
     * group, 978-9943-…) and it sorted that book to the top of the site.
     * Any year no printing can carry becomes blank, as the sync and the
     * Asaxiy import now make it.
     *
     * updated_at is left alone on purpose: bumping it would send the blank
     * to the bot as a change, and the bot side has not been checked for how
     * it treats one.
     */
    public function up(): void
    {
        DB::table('editions')
            ->whereNotNull('year')
            ->where(fn ($year) => $year
                ->where('year', '<', Edition::FIRST_YEAR)
                ->orWhere('year', '>', Edition::lastYear()))
            ->update(['year' => null]);
    }

    /** The wrong years are not worth keeping, so there is nothing to restore. */
    public function down(): void {}
};
