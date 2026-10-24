<?php

use App\Models\Author;
use App\Services\AuthorMerger;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * duplicate slug => kept slug, each pair checked by hand to be one person
     * (authors:duplicates only guesses). The kept one has the most books.
     */
    private const PAIRS = [
        // Hatice Kübra Tongar: 1 book as Hadicha, 16 as Xadicha.
        'hadicha-kubro-tongar' => 'xadicha-kubro-tongar',
    ];

    /**
     * The same merge as Book authors → Merge into…, so the old spelling and
     * link keep leading to the kept author. A database without the pair
     * (staging, a fresh install, a second run) is left as it is.
     */
    public function up(): void
    {
        foreach (self::PAIRS as $duplicate => $kept) {
            $from = Author::where('slug', $duplicate)->first();
            $into = Author::where('slug', $kept)->first();

            if ($from !== null && $into !== null) {
                app(AuthorMerger::class)->merge($from, $into);
            }
        }
    }

    /** The duplicate row is gone; there is nothing to restore it from. */
    public function down(): void {}
};
