<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * /api/sitemap hands the public site everything its sitemap lists in one
 * request: approved books, and the authors and publishers that have one.
 */
class SitemapApiTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    public function test_it_lists_approved_books_and_the_authors_and_publishers_behind_them(): void
    {
        $shown = $this->catalogueBook(
            ['uzbek_title' => 'Chol va dengiz'],
            ['publisher_id' => $this->publisher('Yangi asr avlodi')->id],
            [$this->author('Ernest Hemingway')],
        );
        $hidden = $this->catalogueBook(
            ['uzbek_title' => 'Kutilmoqda', 'status' => ModerationStatus::Pending],
            ['publisher_id' => $this->publisher('Hech kim')->id],
            [$this->author('Yashirin Muallif')],
        );

        $response = $this->getJson('/api/sitemap')->assertOk();

        $this->assertSame([(string) $shown->id], array_column($response->json('books'), 'id'));
        $this->assertSame(['ernest-hemingway'], array_column($response->json('authors'), 'slug'));
        $this->assertSame(['yangi-asr-avlodi'], array_column($response->json('publishers'), 'slug'));
        $this->assertNotNull($response->json('books.0.updatedAt'));
        $this->assertNotContains((string) $hidden->id, array_column($response->json('books'), 'id'));
    }
}
