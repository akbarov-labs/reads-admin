<?php

namespace Tests\Unit;

use App\Services\Telegram\DealDraft;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Telling a sale or a contest from the rest of a channel's posts, and reading its fields. */
class DealDraftTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function deals(): array
    {
        return [
            'a sale' => ["🔥 Barcha kitoblarga 30% chegirma!\n15-noyabrgacha do'konimizda va saytimizda."],
            'Cyrillic Uzbek' => ['Ҳурматли китобхонлар! Барча китобларга 20% чегирма, 1-декабргача.'],
            'Russian' => ['Скидка 25% на всю классику до 30 ноября. Промокод KLASSIKA25'],
            'a promo code' => ["Saytimizda promokod: KITOB15 bilan buyurtma bering, yetkazib berish bepul bo'ladi."],
            'a contest' => ["📚 KONKURS! 3 ta kitobni yutib oling. Shartlar: kanalga obuna bo'ling va postni ulashing."],
        ];
    }

    /** @return array<string, array{string}> */
    public static function notDeals(): array
    {
        return [
            'a pre-order discount (an announcement)' => ['«Hayola» kitobiga oldindan buyurtma: 20% chegirma bilan, 30-oktabrgacha'],
            'contest results' => ["Konkurs g'oliblari aniqlandi! Tabriklaymiz, kitoblaringizni do'kondan olib keting."],
            'opening hours' => ["Bugun do'konlarimiz soat 10:00 dan 20:00 gacha ishlaydi, kutib qolamiz."],
            'too short' => ['20% chegirma'],
        ];
    }

    #[DataProvider('deals')]
    public function test_a_sale_or_contest_post_is_a_deal(string $text): void
    {
        $this->assertTrue(DealDraft::looksLikeDeal($text));
    }

    #[DataProvider('notDeals')]
    public function test_other_posts_are_not(string $text): void
    {
        $this->assertFalse(DealDraft::looksLikeDeal($text));
    }

    public function test_it_reads_the_percentage_code_and_last_day(): void
    {
        $draft = DealDraft::fromText(
            "🔥 Barcha kitoblarga 30% chegirma!\nPromokod: kuz30\n15-noyabrgacha amal qiladi.",
            CarbonImmutable::parse('2026-11-10 09:00'),
        );

        $this->assertSame('discount', $draft['kind']);
        $this->assertSame('Barcha kitoblarga 30% chegirma!', $draft['title']);
        $this->assertSame(30, $draft['discount_percent']);
        $this->assertSame('KUZ30', $draft['promo_code']);
        $this->assertSame('2026-11-15', $draft['ends_on']);
    }

    public function test_a_contest_without_a_discount_is_a_contest(): void
    {
        $draft = DealDraft::fromText("📚 KONKURS!\n3 ta kitobni yutib oling.\nG'oliblar 20-noyabrda aniqlanadi.", CarbonImmutable::parse('2026-11-10'));

        $this->assertSame('contest', $draft['kind']);
        $this->assertNull($draft['discount_percent']);
        $this->assertSame('2026-11-20', $draft['ends_on']);
    }
}
