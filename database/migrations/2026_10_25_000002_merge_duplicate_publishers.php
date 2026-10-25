<?php

use App\Models\Publisher;
use App\Services\PublisherMerger;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * duplicate slug => kept slug: one publisher with "nashr" or "books"
     * glued on to its name, checked by hand against the live catalogue on
     * 2026-10-25. The kept one has the most books.
     */
    private const PAIRS = [
        'hilolnashr' => 'hilol-nashr',
        'kitobdor' => 'kitobdornashr',
        'factorbooks' => 'factor-books',
        'iqronashr' => 'iqro-nashr',
        'zumarbooks' => 'zumar-books',
        'sarmoyabooks' => 'sarmoya',
        'tirilishnashr' => 'tirilish',
        'zaminnashr' => 'zamin-nashr',
        'tasnimbooks' => 'tasnim',
        'tavfiq-nashr' => 'tavfiqnashr',
        'irfonnashr' => 'irfon-nashr',
        'azbobooks' => 'azbo',
    ];

    /**
     * The same merge as Publishers → Merge into…, so the old spelling and
     * link keep leading to the kept publisher. A database without a pair
     * (staging, a fresh install, a second run) is left as it is.
     */
    public function up(): void
    {
        foreach (self::PAIRS as $duplicate => $kept) {
            $from = Publisher::where('slug', $duplicate)->first();
            $into = Publisher::where('slug', $kept)->first();

            if ($from !== null && $into !== null) {
                app(PublisherMerger::class)->merge($from, $into);
            }
        }
    }

    /** The duplicate rows are gone; there is nothing to restore them from. */
    public function down(): void {}
};
