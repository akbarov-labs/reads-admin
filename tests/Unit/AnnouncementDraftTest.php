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

        $this->assertSame('Kecha va kunduz — Choʻlpon', $draft['title']);
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
        $this->assertTrue($first->hasPhoto);
        $this->assertFalse($second->hasPhoto);
        $this->assertNull($first->forwardedFrom);
    }

    public function test_a_forwarded_post_says_where_it_came_from(): void
    {
        $post = fn (string $forward) => TelegramChannel::parsePage(<<<HTML
            <div class="tgme_widget_message js-widget_message" data-post="anorbooks_nashr/5">
              <div class="tgme_widget_message_forwarded_from accent_color">Forwarded from {$forward}</div>
              <div class="tgme_widget_message_text js-message_text">Yangi kitob</div>
            </div>
            HTML)[0];

        $linked = $post('<a class="tgme_widget_message_forwarded_from_name" href="https://t.me/Ildizkitoblari/10671">Ildiz</a>');
        $this->assertSame('Ildizkitoblari', $linked->forwardedFrom);
        $this->assertTrue($linked->isForwardedFromElsewhere());

        $hidden = $post('<span class="tgme_widget_message_forwarded_from_name">Someone</span>');
        $this->assertSame('', $hidden->forwardedFrom);
        $this->assertTrue($hidden->isForwardedFromElsewhere());

        $own = $post('<a class="tgme_widget_message_forwarded_from_name" href="https://t.me/AnorBooks_Nashr/3">Anor</a>');
        $this->assertFalse($own->isForwardedFromElsewhere());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function titles(): array
    {
        return [
            // From real channels (Akademnashr, Mitti olam, X Group Books), November 2026.
            'after hashtag lines' => ["#yangi_nashr
#sotuvda

📘 Iste’dod aristokratiyasi

Meritokratiya zamonaviy dunyoni qanday yaratgani haqida", 'Iste’dod aristokratiyasi'],
            'after a heading' => ["⏳ TEZ KUNDA!

🧠 100 ta o‘yin — zukko bolalar uchun!", '100 ta o‘yin — zukko bolalar uchun!'],
            'quoted after a heading' => ["📚 YANGI KITOB!

❤️ Jeyn Ostin — “SEVGI VA SADOQAT”

Xato qilingan tanlovning bahosi", 'SEVGI VA SADOQAT'],
            'not a quote inside a paragraph' => ["#sotuvda

📘 Istisno

📖 Fragment va mundarija

To‘plamdagi o‘n yillik ijod mahsuli uch qismga bo‘lingan holda davriylashtirilgan. Birinchi qism “Simurg‘” – o‘ttiz qush deb nomlanishi bejiz emas.", 'Istisno'],
        ];
    }

    #[DataProvider('titles')]
    public function test_the_title_is_the_books_not_the_posts_heading(string $text, string $expected): void
    {
        $this->assertSame($expected, AnnouncementDraft::fromText($text, $this->posted)['title']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function channelPosts(): array
    {
        return [
            // From real channels (Hilol-nashr, Akademnashr, Zabarjad Media, Asaxiy Books), November 2026.
            'a new book by its title' => ["#тез_кунда\n🎉 Янгилик\nАбдулкарим Ҳасаннинг янги «Хаёла» китоби нашрдан чиқяпти.", true],
            'a reprint' => ["🎉 «Тайсирус сарф» китоби нашр қилинди\n✨ Араб тили қоидаларини тушунишда қийналяпсизми?\n👉 Нархи: 21 500 сўм", true],
            'a reprint in Russian' => ['🎉 Книга «Счастливая семья» переиздана в 5-й раз и снова поступила в продажу.', true],
            'hashtags without quotes' => ["#yangi_nashr\n#sotuvda\n\n📘 Chili loyihasi\n\n📖 Fragment va mundarija\n\n“Chikago yigitlari” va neoliberalizm tanazzuli hikoyasi", true],
            'a pre-order hashtag' => ["#oldindan_buyurtma\n\n📘 Istisno\n\nMehrNozning she’riy toʻplami uchun buyurtma berish boshlandi.", true],
            'a pre-order without a price' => ['«Hayola» kitobiga oldindan buyurtma ochildi!', true],
            'a pre-order discount' => ["«Hayola» kitobiga oldindan buyurtmada 20% chegirma: 79 000 so'm", true],
            'a book already in print, with a price' => ["✨ Ota-onaga yaxshilik qilishning fazilati\n👉 Narxi: 84 000 so'm\nSotib olish uchun: @hilol_reception", false],
            'a store promotion' => ["💡 Erkinlik va iqtisodiyot o‘rtasida qanday bog‘liqlik bor?\n📚 Milton Fridman — \"Kapitalizm va erkinlik\"\nNarxi: 99 000 soʻm", false],
            'the week\'s top five' => ["⭐️ O‘tgan haftaning TOP-5 kitobi bilan tanishamiz!\n1️⃣ Molxona - 30 000 so‘m\n2️⃣ Kichkina shahzoda - 41 700 so'm\nSotuvda!", false],
            'bestsellers' => ["📚 Октябрь ойида энг кўп сотилган китоблар билан танишинг\n1. «Бахтиёр оила» – 78 000 сўм\nСотувда", false],
            'a priced list' => ["#sotuvda\n1. O‘zbekiston tavalludi — 150 000 so‘m\n2. Shayboniylar davrining mashhur olimlari — 120 000 so‘m\n3. Vatan tarixi — 210 000 so‘m", false],
            'a contest prize' => ["Random g‘olibi Gulsevar\nKarta raqamingizni yozib qoldiring! 50.000 so‘mdan pul mukofoti. Yangi kitob sizni kutmoqda", false],
            'a magazine issue with words between' => ["🎉 Янгилик! «Hilol» журналининг янги 83-сони нашрдан чиқди\n👉 Нархи: 10 000 сўм", false],
            'a magazine issue' => ['#тез_кунда 🎉 Янгилик. «Ҳилол» журналининг 83-сони тез кунда барча дўконларимизда сотувга чиқади.', false],
            'a sale on what is in stock' => ["Diqqat: bayramona chegirma 🏷️\nUshbu kun munosabati bilan sotuvda mavjud barcha tarjima adabiyotlarimiz uchun 10% chegirma e’lon qilamiz.", false],
            'a one-word reply' => ['Sotuvda!', false],
            'an event' => ["«15 йил маърифат таратиб» тадбиридан видеолавҳа\n🌐 Тадбирни томоша қилинг", false],
            'opening hours' => ["#маълумот\nБугун қуйидаги дўконларимиз ишлайди:\n– Бош дўкон;\n– Хадра;", false],
            'no text' => ['', false],
        ];
    }

    #[DataProvider('channelPosts')]
    public function test_which_channel_posts_look_like_announcements(string $text, bool $expected): void
    {
        $this->assertSame($expected, AnnouncementDraft::looksLikeAnnouncement($text));
    }
}
