<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Services\CatalogueRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** 2026_10_24_000010 folds the hand-checked duplicate pairs, and does nothing where they are not both there. */
class MergeDuplicateAuthorsMigrationTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_24_000010_merge_duplicate_authors.php'))->up();
    }

    public function test_hadicha_becomes_part_of_xadicha_and_stays_so(): void
    {
        $kept = $this->author('Xadicha Kubro Tongar');
        $duplicate = $this->author('Hadicha Kubro Tongar');
        $this->catalogueBook(['uzbek_title' => 'Baqirmaydigan onalar'], ['isbn' => '9789943000001'], authors: [$kept]);
        $moved = $this->catalogueBook(['uzbek_title' => 'Ilk oʻn ikki oy'], ['isbn' => '9789943000002'], authors: [$duplicate]);

        $this->migrate();

        $this->assertNull(Author::find($duplicate->id));
        $this->assertSame($kept->id, $moved->authors()->sole()->id);
        $this->assertSame(2, $kept->books()->count());
        $this->assertSame($kept->id, app(CatalogueRecords::class)->author('Hadicha Kubro Tongar')->id, 'the bot does not make it again');

        $this->getJson('/api/authors/hadicha-kubro-tongar')->assertOk()->assertJsonPath('data.slug', 'xadicha-kubro-tongar');
    }

    public function test_a_database_without_both_of_them_is_left_alone(): void
    {
        $alone = $this->author('Hadicha Kubro Tongar');

        $this->migrate();
        $this->migrate();

        $this->assertNotNull(Author::find($alone->id));
    }
}
