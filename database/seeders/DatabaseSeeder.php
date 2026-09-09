<?php

namespace Database\Seeders;

use App\Enums\BookRole;
use App\Enums\SocialPlatform;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\LanguagePair;
use App\Models\SocialLink;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Reads Admin',
            'email' => 'admin@reads.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $translatorUser = User::create([
            'name' => 'Ilhom Salimov',
            'email' => 'ilhom@reads.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        $translator = Translator::create([
            'user_id' => $translatorUser->id,
            'slug' => 'ilhom-salimov',
            'name' => 'Ilhom Salimov',
            'avatar_path' => 'avatars/ilhom-salimov.svg',
            'title' => [
                'uz' => 'Tarjimon va Muallif',
                'ru' => 'Переводчик и автор',
                'en' => 'Translator & Author',
            ],
            'location' => [
                'uz' => "Toshkent, O'zbekiston",
                'ru' => 'Ташкент, Узбекистан',
                'en' => 'Tashkent, Uzbekistan',
            ],
            'bio' => [
                'uz' => "Adabiy sharhlovchi, tarjimon va «Zulfiqor» kitobi muallifi. Jahon adabiyotining sara namunalarini o'zbek tiliga o'girish, badiiy tahlil va adabiy jarayonlarni yoritish bilan shug'ullanadi. «The Stranger» adabiy blogi asoschisi. Tarjimada matnning ritmi, estetik kuchi va asl ohangini o'zbek tilida tabiiy jaranglashiga alohida e'tibor qaratadi.",
            ],
            'years_active' => 5,
            'start_year' => 2020,
        ]);

        $socialLinks = [
            SocialPlatform::Telegram->value => 'https://t.me/ilhom_salimovv',
            SocialPlatform::Instagram->value => 'https://instagram.com/ilhom_salimovv',
        ];

        $i = 0;
        foreach ($socialLinks as $platform => $url) {
            SocialLink::create([
                'translator_id' => $translator->id,
                'platform' => $platform,
                'url' => $url,
                'sort_order' => $i++,
            ]);
        }

        $pairs = [
            ['Ingliz tili', "O'zbek tili"],
            ['Fransuz tili', "O'zbek tili"],
            ['Turk tili', "O'zbek tili"],
        ];

        foreach ($pairs as $i => [$from, $to]) {
            LanguagePair::create([
                'translator_id' => $translator->id,
                'from_language' => $from,
                'to_language' => $to,
                'sort_order' => $i,
            ]);
        }

        $books = [
            [
                'role' => BookRole::Author,
                'uzbek_title' => 'Zulfiqor',
                'original_title' => 'Adabiy taqrizlar va esselar',
                'author' => 'Ilhom Salimov',
                'source_language' => "O'zbek tili",
                'publisher' => 'Yangi Kitob',
                'year' => 2023,
                'cover_path' => 'covers/letters-from-the-salt-coast.svg',
                'excerpt_source_language' => "O'zbek tili",
                'excerpt_source_text' => "Kitob o'qish — bu shunchaki vaqt o'tkazish vositasi emas, balki insonning o'z ichki olamiga qilgan eng og'ir va eng go'zal sayohatidir. Adabiyot bizga o'zimiz qo'rqib qaraydigan haqiqatlarni ko'rsatadi.",
                'excerpt_translated_text' => "Adabiyotning vazifasi faqat zavq berish emas, balki ruhni uyg'otish, tafakkurni harakatga keltirishdir. Har bir yaxshi kitob inson ichidagi qaysidir qorong'i burchakka yorug'lik olib kiradi.",
            ],
            [
                'role' => BookRole::Translator,
                'uzbek_title' => 'Maymunlar sayyorasi',
                'original_title' => 'La Planète des singes',
                'author' => 'Pierre Boulle',
                'source_language' => 'Fransuz tili',
                'publisher' => 'Asaxiy Books',
                'year' => 2023,
                'cover_path' => 'covers/rivers-without-a-name.svg',
                'excerpt_source_language' => 'Fransuz tili',
                'excerpt_source_text' => "Je confie ce manuscrit à l'espace, non pas dans l'espoir d'obtenir du secours, mais pour servir, peut-être, à conjurer l'effroyable péril qui menace la race humaine.",
                'excerpt_translated_text' => "Men bu qo'lyozmani fazoga yo'llamoqdaman, najot kutish umidida emas, balki insoniyat zotiga tahdid solayotgan dahshatli xavfning oldini olishga balki yordam berar degan niyatda.",
            ],
            [
                'role' => BookRole::Translator,
                'uzbek_title' => "Terabitiya ko'prigi",
                'original_title' => 'Bridge to Terabithia',
                'author' => 'Katherine Paterson',
                'source_language' => 'Ingliz tili',
                'publisher' => 'Zabarjad Media',
                'year' => 2022,
                'cover_path' => 'covers/glass-orchard.svg',
                'excerpt_source_language' => 'Ingliz tili',
                'excerpt_source_text' => 'It was Terabithia, the magical kingdom where they were king and queen, and where no enemies could defeat them, as long as they had courage and each other.',
                'excerpt_translated_text' => "Bu Terabitiya edi — ular shoh va malika bo'lgan, jasorat va bir-birlariga ishonch bor ekan, hech qanday yov ularni mag'lub eta olmaydigan sehrli saltanat.",
            ],
            [
                'role' => BookRole::Translator,
                'uzbek_title' => 'Londondan muhabbat ila',
                'original_title' => 'With Love from London',
                'author' => 'Sarah Jio',
                'source_language' => 'Ingliz tili',
                'publisher' => 'Nihol',
                'year' => 2023,
                'cover_path' => 'covers/letters-from-the-salt-coast.svg',
            ],
            [
                'role' => BookRole::Translator,
                'uzbek_title' => 'Ishqning yashirin qiyofasi',
                'original_title' => 'Aşkın Saklı Yüzü',
                'author' => 'Halis Karabenli',
                'source_language' => 'Turk tili',
                'publisher' => 'Factor Books',
                'year' => 2021,
                'cover_path' => 'covers/glass-orchard.svg',
            ],
            [
                'role' => BookRole::Editor,
                'uzbek_title' => 'Vaqt mashinasi',
                'original_title' => 'The Time Machine',
                'author' => 'H.G. Wells',
                'source_language' => 'Ingliz tili',
                'publisher' => 'Yangi Asr Avlodi',
                'year' => 2022,
                'cover_path' => 'covers/rivers-without-a-name.svg',
            ],
        ];

        foreach ($books as $i => $book) {
            Book::create([...$book, 'translator_id' => $translator->id, 'sort_order' => $i]);
        }

        $this->command?->info("Admin login: {$admin->email} / password");
        $this->command?->info("Translator login: {$translatorUser->email} / password");
    }
}
