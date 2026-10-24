<?php

namespace Tests\Unit;

use App\Services\Telegram\AnnouncementDraft;
use App\Services\Telegram\TelegramChannel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A first guess at an announcement from a post's text, and reading post links. */
class AnnouncementDraftTest extends TestCase
{
    private CarbonImmutable $posted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posted = CarbonImmutable::parse('2026-10-20 09:00');
    }

    public function test_a_typical_preorder_post(): void
    {
        $text = <<<'TXT'
            📚 Yangi kitob! «Jinoyat va jazo»
            ✍️ Muallif: Fyodor Dostoyevskiy
            🔄 Tarjimon: Ibrohim Gʻafurov

            Narxi: 120 000 so'm
            Oldindan buyurtmada: 95 000 so'm — 25-oktabrgacha!
            TXT;

        $this->assertSame([
            'title' => 'Jinoyat va jazo',
            'author_name' => 'Fyodor Dostoyevskiy',
            'translator_name' => 'Ibrohim Gʻafurov',
            'price' => 120000,
            'preorder_price' => 95000,
            'preorder_deadline' => '2026-10-25',
        ], AnnouncementDraft::fromText($text, $this->posted));
    }

    public function test_a_russian_post_with_a_numeric_deadline(): void
    {
        $draft = AnnouncementDraft::fromText("Предзаказ до 01.11\n\"Мастер и Маргарита\"\nАвтор: М. Булгаков\nЦена 89.000 сум", $this->posted);

        $this->assertSame('Мастер и Маргарита', $draft['title']);
        $this->assertSame('М. Булгаков', $draft['author_name']);
        $this->assertSame(89000, $draft['preorder_price']);
        $this->assertNull($draft['price']);
        $this->assertSame('2026-11-01', $draft['preorder_deadline']);
    }

    public function test_a_new_book_has_a_price_and_no_deadline(): void
    {
        $draft = AnnouncementDraft::fromText("Sotuvda!\nKecha va kunduz — Choʻlpon\n65000 som", $this->posted);

        $this->assertSame('Sotuvda!', $draft['title']);
        $this->assertSame(65000, $draft['price']);
        $this->assertNull($draft['preorder_price']);
        $this->assertNull($draft['preorder_deadline']);
    }

    public function test_a_day_already_past_this_year_is_next_years(): void
    {
        $draft = AnnouncementDraft::fromText('Oldindan buyurtma 5-yanvargacha, 50 000 so\'m', $this->posted);

        $this->assertSame('2027-01-05', $draft['preorder_deadline']);
    }

    #[DataProvider('notDeadlines')]
    public function test_numbers_that_are_not_a_deadline(string $text): void
    {
        $this->assertNull(AnnouncementDraft::fromText("Oldindan buyurtma! {$text}", $this->posted)['preorder_deadline']);
    }

    public static function notDeadlines(): array
    {
        return [
            'a price with a dot' => ['95.000 so\'m'],
            '"25 times"' => ['25 marta oʻqilgan'],
            'an impossible date' => ['31.02'],
        ];
    }

    public function test_an_empty_post_still_gives_a_title(): void
    {
        $this->assertSame('Untitled post', AnnouncementDraft::fromText(null)['title']);
    }

    #[DataProvider('links')]
    public function test_post_links(string $link, ?array $expected): void
    {
        $this->assertSame($expected, TelegramChannel::parseLink($link));
    }

    public static function links(): array
    {
        return [
            'plain' => ['https://t.me/hilolnashr/1234', ['hilolnashr', 1234]],
            'web preview' => ['https://t.me/s/hilolnashr/1234', ['hilolnashr', 1234]],
            'no scheme, with a query' => ['t.me/Hilol_Nashr/77?single', ['Hilol_Nashr', 77]],
            'telegram.me' => ['http://telegram.me/hilolnashr/5/', ['hilolnashr', 5]],
            'a private channel' => ['https://t.me/c/1234567/89', null],
            'a channel, not a post' => ['https://t.me/hilolnashr', null],
            'another site' => ['https://evil.example/hilolnashr/1', null],
            'a lookalike host' => ['https://t.me.evil.example/hilolnashr/1', null],
        ];
    }

    public function test_usernames(): void
    {
        $this->assertSame('hilol_nashr', TelegramChannel::username('@hilol_nashr'));
        $this->assertSame('hilolnashr', TelegramChannel::username('https://t.me/hilolnashr'));
        $this->assertNull(TelegramChannel::username('hilol nashr'));
        $this->assertNull(TelegramChannel::username('@ab'));
    }

    public function test_a_web_preview_page_is_read_post_by_post(): void
    {
        $posts = TelegramChannel::parsePage(file_get_contents(__DIR__.'/../Fixtures/telegram-channel.html'));

        $this->assertCount(2, $posts);
        [$first, $second] = $posts;

        $this->assertSame('hilolnashr', $first->channel);
        $this->assertSame(41, $first->id);
        $this->assertSame("«Jinoyat va jazo»\nMuallif: Fyodor Dostoyevskiy\nNarxi: 120 000 so'm 📚", $first->text);
        $this->assertSame('https://cdn4.telesco.pe/file/cover41.jpg', $first->photoUrl);
        $this->assertSame('2026-10-20T09:15:00+00:00', $first->postedAt->toIso8601String());
        $this->assertSame('https://t.me/hilolnashr/41', $first->url());

        // A video's thumbnail stands in for a photo; a quoted reply's text is not the post's.
        $this->assertSame(42, $second->id);
        $this->assertSame('Sotuvda!', $second->text);
        $this->assertSame('https://cdn4.telesco.pe/file/thumb42.jpg', $second->photoUrl);
    }
}
