<?php

namespace Tests\Feature;

use App\Models\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** 2026_10_24_000020 gives imported Asaxiy descriptions back their paragraphs and drops the shop's announcement. */
class AsaxiyDescriptionMigrationTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private const FLAT = "Asaxiy Books loyihasida yana bir PREMYERA - “Bog'dagi uy” kitobi sotuvda! Yoz keldi. Kitob davomida: • sabr; • umid. Oxirgi gap. Ko'proq ko'rish";

    public function test_a_one_line_asaxiy_description_is_tidied_and_updated_at_is_left_alone(): void
    {
        $edition = $this->catalogueBook([], ['isbn' => '9789943505148', 'source_url' => 'https://asaxiy.uz/product/bogdagi-uy'])->editions->sole();
        DB::table('editions')->where('id', $edition->id)->update(['description' => self::FLAT, 'updated_at' => '2026-03-01 10:00:00']);

        $this->migrate();

        $this->assertSame("Yoz keldi.\n\nKitob davomida:\n• sabr;\n• umid.\n\nOxirgi gap.", Edition::find($edition->id)->description);
        $this->assertSame('2026-03-01 10:00:00', (string) DB::table('editions')->where('id', $edition->id)->value('updated_at'), 'the bot is not told about it');
    }

    public function test_text_with_line_breaks_another_source_and_a_books_own_description_are_left_alone(): void
    {
        $edited = $this->catalogueBook(['uzbek_title' => 'Edited'], ['isbn' => '9789943000001', 'source_url' => 'https://asaxiy.uz/product/1'])->editions->sole();
        $bot = $this->catalogueBook(['uzbek_title' => 'Bot'], ['isbn' => '9789943000002'])->editions->sole();
        $own = $this->catalogueBook(['uzbek_title' => 'Own', 'description' => self::FLAT], ['isbn' => '9789943000003', 'source_url' => 'https://asaxiy.uz/product/3']);
        $byHand = "Asaxiy Books loyihasida yana bir PREMYERA - kitob sotuvda!\nAdmin shunday yozdi.";
        DB::table('editions')->where('id', $edited->id)->update(['description' => $byHand]);
        DB::table('editions')->where('id', $bot->id)->update(['description' => self::FLAT]);

        $this->migrate();

        $this->assertSame($byHand, Edition::find($edited->id)->description);
        $this->assertSame(self::FLAT, Edition::find($bot->id)->description);
        $this->assertSame(self::FLAT, $own->fresh()->description);
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_24_000020_restore_asaxiy_description_paragraphs.php'))->up();
    }
}
