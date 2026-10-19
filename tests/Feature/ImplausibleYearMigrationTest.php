<?php

namespace Tests\Feature;

use App\Models\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** 2026_10_19_000001 blanks the years a printing cannot carry, and only those. */
class ImplausibleYearMigrationTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_only_implausible_years_are_cleared_and_nothing_else_is_touched(): void
    {
        $wrong = $this->catalogueBook(edition: ['isbn' => '9789943505148', 'year' => 2020])->editions()->sole();
        $old = $this->catalogueBook(['uzbek_title' => 'Eski'], ['isbn' => '9789943000001', 'year' => 2020])->editions()->sole();
        $fine = $this->catalogueBook(['uzbek_title' => 'Yaxshi'], ['isbn' => '9789943000002', 'year' => 2025])->editions()->sole();
        $blank = $this->catalogueBook(['uzbek_title' => 'Yilsiz'], ['isbn' => '9789943000003', 'year' => null])->editions()->sole();

        // Straight to the column, as the old sync wrote them.
        DB::table('editions')->where('id', $wrong->id)->update(['year' => 9943, 'updated_at' => '2026-03-01 10:00:00']);
        DB::table('editions')->where('id', $old->id)->update(['year' => 1200]);

        (require database_path('migrations/2026_10_19_000001_clear_implausible_edition_years.php'))->up();

        $this->assertNull(Edition::find($wrong->id)->year);
        $this->assertNull(Edition::find($old->id)->year);
        $this->assertSame(2025, Edition::find($fine->id)->year);
        $this->assertNull(Edition::find($blank->id)->year);
        $this->assertSame('2026-03-01 10:00:00', (string) DB::table('editions')->where('id', $wrong->id)->value('updated_at'), 'the bot is not told about it');
    }
}
