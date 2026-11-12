<?php

namespace Tests\Unit;

use App\Services\Telegram\DealDraft;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Telling a sale or a contest from the rest of a channel's posts, and reading its fields. */
class DealDraftTest extends TestCase
{
    /** Nido Nashr's post of 12 November 2026 (t.me/nidonashr/210), in its own spelling. */
    private const MEETING = "Bitta xato qançalik qimmatga tuşadi? 🕊\n"
        ."Tarix bu savolga 50 marta javob bergan. Hofiz Ibn Kasir jamlagan qissalarning har birida bitta qaror va uning oqibati bor. Ba’zan bir lahzalik tanlov butun umrga iz qoldiradi.\n"
        ."📖 «Yomon oqibat haqida 50 qissa» kitobini özbek tiliga tarjima qilib, badiiy tilda qayta jonlantirgan ustoz Asliddin Abdulhodiy bilan uçraşuvga taklif qilamiz.\n"
        ."Kitob qanday tarjima qilingani, qissalardagi ibratlar bugungi hayotimizga qanday boğlanişi haqida gaplaşamiz. Savollaringizni ustozning özlariga beriş imkoniyati bor.\n"
        ."🗓 14-noyabr, soat 17:00\n"
        ."📍 Toshkent kitob markazi\n"
        .'Kitobsevar döstingizni ham belgilang, birga keling 🤍';

    /** @return array<string, array{string}> */
    public static function deals(): array
    {
        return [
            'a sale' => ["🔥 Barcha kitoblarga 30% chegirma!\n15-noyabrgacha do'konimizda va saytimizda."],
            'Cyrillic Uzbek' => ['Ҳурматли китобхонлар! Барча китобларга 20% чегирма, 1-декабргача.'],
            'Russian' => ['Скидка 25% на всю классику до 30 ноября. Промокод KLASSIKA25'],
            'a promo code' => ["Saytimizda promokod: KITOB15 bilan buyurtma bering, yetkazib berish bepul bo'ladi."],
            'a contest' => ["📚 KONKURS! 3 ta kitobni yutib oling. Shartlar: kanalga obuna bo'ling va postni ulashing."],
            'a contest with tanlov' => ["Yangi tanlovga marhamat! Eng yaxshi taqriz muallifiga kitob sovg'a qilamiz."],
            'a meeting with the translator' => [self::MEETING],
            'a book launch, Cyrillic' => ['Ҳурматли китобхонлар! Янги китобимиз тақдимоти 20-ноябрь соат 15:00 да Миллий кутубхонада бўлиб ўтади.'],
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
            '"tanlov" as a choice' => ["Ba'zan bir lahzalik tanlov butun umrga iz qoldiradi. Kitobni o'qing va o'zingiz xulosa qiling."],
            'a meeting already held, no time' => ["Kecha muallif bilan uchrashuv bo'lib o'tdi, kelgan barcha kitobxonlarga rahmat!"],
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

    public function test_a_meeting_is_an_event_on_its_day_with_when_and_where_first(): void
    {
        $draft = DealDraft::fromText(self::MEETING, CarbonImmutable::parse('2026-11-12 12:41'));

        $this->assertSame('event', $draft['kind']);
        $this->assertSame('2026-11-14', $draft['ends_on']);
        $this->assertNull($draft['discount_percent']);
        $this->assertStringStartsWith("🗓 14-noyabr, soat 17:00\n📍 Toshkent kitob markazi\n", $draft['details']);
    }

    public function test_an_events_time_with_a_dot_is_not_its_day(): void
    {
        $draft = DealDraft::fromText("Muallif bilan uchrashuv!\nSoat 18.30 da, 25-noyabr kuni, Alisher Navoiy kutubxonasida.", CarbonImmutable::parse('2026-11-12'));

        $this->assertSame('event', $draft['kind']);
        $this->assertSame('2026-11-25', $draft['ends_on']);
    }

    public function test_a_sale_at_a_meeting_stays_a_discount(): void
    {
        $draft = DealDraft::fromText("Uchrashuvda barcha kitoblarga 20% chegirma!\n15-noyabr soat 16:00, do'konimizda.", CarbonImmutable::parse('2026-11-12'));

        $this->assertSame('discount', $draft['kind']);
    }
}
