<?php

namespace Tests\Unit;

use App\Support\Asaxiy\AsaxiyCatalog;
use App\Support\UzbekText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UzbekTextTest extends TestCase
{
    #[DataProvider('transliterations')]
    public function test_it_transliterates_uzbek_cyrillic(string $cyrillic, string $latin): void
    {
        $this->assertSame($latin, UzbekText::toLatin($cyrillic));
    }

    public static function transliterations(): array
    {
        return [
            ['Ҳилол-нашр', 'Hilol-nashr'],
            ['Ўзбекистон', "O'zbekiston"],
            ['Ғафур Ғулом', "G'afur G'ulom"],
            ['Янги Китоб', 'Yangi Kitob'],
            ['Бошқа нашриётлар', 'Boshqa nashriyotlar'],
            ['Ер юзи', 'Yer yuzi'],
            ['Шеърият', "She'riyat"],
            ['Цирк', 'Sirk'],
            ['Милиция', 'Militsiya'],
            ['АҚШ', 'AQSH'],
            ['Миллион долларлик хатолар', 'Million dollarlik xatolar'],
            ['Марио Пьюзо', 'Mario Pyuzo'],
            // Cyrillic letters hiding inside Latin text.
            ['Аhmad', 'Ahmad'],
            ['А4', 'A4'],
        ];
    }

    public function test_spellings_of_one_publisher_share_a_key(): void
    {
        $key = UzbekText::publisherKey('Hilol nashr');

        foreach (['HILOL-Nashr', 'Ҳилол-нашр', 'Hilol-nashr', 'hilol nashriyoti'] as $spelling) {
            $this->assertSame($key, UzbekText::publisherKey($spelling), $spelling);
        }

        $this->assertNotSame($key, UzbekText::publisherKey('Hilol media'));
        $this->assertSame(UzbekText::publisherKey("G'afur G'ulom"), UzbekText::publisherKey('G`afur G`ulom'));
        $this->assertSame(UzbekText::publisherKey('Nihol'), UzbekText::publisherKey('Nihol nashr'));
    }

    public function test_isbn_is_normalised_or_rejected(): void
    {
        $this->assertSame('9789943231986', AsaxiyCatalog::isbn('978-9943-23-198-6'));
        $this->assertSame('9781335928283', AsaxiyCatalog::isbn("\u{200E} 978-1335928283"));
        $this->assertSame('9789943876958', AsaxiyCatalog::isbn('978-9943-8769-5-8 / 978-9943-8769-1-0'));
        $this->assertSame('9787561937266', AsaxiyCatalog::isbn('9787561937266, 9787561937808'));
        $this->assertNull(AsaxiyCatalog::isbn('21813728'));
        $this->assertNull(AsaxiyCatalog::isbn('978-9943-6464-7-9-6'));
        $this->assertNull(AsaxiyCatalog::isbn(null));
    }

    public function test_the_author_prefix_comes_off_the_title(): void
    {
        $this->assertSame(['Katta o\'yin', 'Piter Hopkirk'], AsaxiyCatalog::splitTitle("Piter Hopkirk: Katta o'yin", 'Piter Hopkirk'));
        $this->assertSame(['Iblislar', 'Fyodor Dostoyevskiy'], AsaxiyCatalog::splitTitle('Dostoyevskiy: Iblislar', 'Fyodor Dostoyevskiy'));
        $this->assertSame(['Kapitalizm va erkinlik', 'Milton Fridman'], AsaxiyCatalog::splitTitle('Milton Fridman: Kapitalizm va erkinlik', null));
        // Series labels are not people.
        $this->assertSame(['Dunyo daholari: Eynshteyn', null], AsaxiyCatalog::splitTitle('Dunyo daholari: Eynshteyn', null));
        $this->assertSame(["To'plam: She'rlar", null], AsaxiyCatalog::splitTitle("To'plam: She'rlar", null));
    }

    public function test_an_uzbek_cyrillic_row_comes_out_latin(): void
    {
        $book = AsaxiyCatalog::normalize([
            'title' => 'Анбиё Йилдирим: Ҳазиллашиш одоби (кирилл)',
            'author' => null,
            'translator' => 'Достон Мустафоев, Ойбек Сотиболдиев',
            'isbn' => '978-9943-23-198-6',
            'publisher' => 'Ҳилол-нашр',
            'language' => "O'zbekcha",
            'script' => 'Kirillcha',
            'paper_format' => 'А5',
            'pages' => '744',
            'published_year' => '20221',
            'description' => 'Mahsulot ta\'rifi Китоб ҳақида',
            'article' => 'T1',
            'url' => 'https://asaxiy.uz/product/x',
        ]);

        $this->assertSame('Hazillashish odobi', $book['title']);
        $this->assertSame('Anbiyo Yildirim', $book['author']);
        $this->assertSame(['Doston Mustafoyev', 'Oybek Sotiboldiyev'], $book['translators']);
        $this->assertSame('Hilol-nashr', $book['publisher']);
        $this->assertSame("O'zbek tili", $book['language']);
        $this->assertSame('Kirill', $book['script']);
        $this->assertSame('A5', $book['paper_format']);
        $this->assertSame(744, $book['pages']);
        $this->assertNull($book['year']);
        $this->assertSame('Kitob haqida', $book['description']);
    }

    public function test_russian_rows_keep_their_spelling(): void
    {
        $book = AsaxiyCatalog::normalize([
            'title' => 'Лев Толстой: Война и мир',
            'author' => 'Лев Толстой',
            'publisher' => 'Эксмо',
            'language' => 'Ruscha',
        ]);

        $this->assertSame('Война и мир', $book['title']);
        $this->assertSame('Эксмо', $book['publisher']);
        $this->assertSame('Rus tili', $book['language']);
    }

    public function test_a_russian_book_tagged_uzbek_is_left_in_cyrillic(): void
    {
        $book = AsaxiyCatalog::normalize([
            'title' => 'Анастасия Залога: Любовь к себе',
            'author' => 'Анастасия Залога',
            'publisher' => 'Эксмо',
            'language' => "O'zbekcha",
        ]);

        $this->assertSame('Любовь к себе', $book['title']);
        $this->assertSame('Эксмо', $book['publisher']);
        $this->assertSame('Rus tili', $book['language']);
    }

    public function test_listing_notes_come_off_the_title(): void
    {
        $this->assertSame('Ishbilarmon kishilar', AsaxiyCatalog::normalize(['title' => 'Ishbilarmon kishilar (yumshoq)', 'language' => "O'zbekcha"])['title']);
        $this->assertSame('Hatiko', AsaxiyCatalog::normalize(['title' => 'Hatiko (кирилл)', 'language' => "O'zbekcha"])['title']);
    }

    public function test_listing_notes_that_split_one_book_come_off(): void
    {
        foreach (["Andisha va g'urur (", "Andisha va g'urur (2023)", "Andisha va g'urur (qattiq)", "Andisha va g'urur (Yangi Asr Avlodi)", "Andisha va g'urur."] as $title) {
            $this->assertSame("Andisha va g'urur", AsaxiyCatalog::stripListingNotes($title, 'Yangi Asr Avlodi'), $title);
        }

        $this->assertSame('Kecha va Kunduz', AsaxiyCatalog::stripListingNotes('Kecha va Kunduz (Ziyo nashr)', 'Boshqa'));
        // A parenthetical that is part of the title stays.
        $this->assertSame('Garri Potter (Afsonaviy nashr emas)', AsaxiyCatalog::stripListingNotes('Garri Potter (Afsonaviy nashr emas)', null));
        $this->assertSame('Dunyo (tanlangan hikoyalar)', AsaxiyCatalog::stripListingNotes('Dunyo (tanlangan hikoyalar)', 'Asaxiy Books'));
    }

    public function test_placeholder_publishers_are_dropped(): void
    {
        $this->assertNull(AsaxiyCatalog::normalize(['title' => 'X', 'publisher' => 'Бошқа нашриётлар', 'language' => 'Ruscha'])['publisher']);
        $this->assertNull(AsaxiyCatalog::normalize(['title' => 'X', 'publisher' => '1121'])['publisher']);
    }
}
