<?php

namespace Tests\Unit;

use App\Support\Asaxiy\AsaxiyCatalog;
use App\Support\Asaxiy\AsaxiyDescription;
use App\Support\Asaxiy\AsaxiyPage;
use App\Support\UzbekText;
use PHPUnit\Framework\TestCase;

class AsaxiyDescriptionTest extends TestCase
{
    /** One line, as the workbook has every description: an announcement, prose, two lists, the button label. */
    private const FLAT = "Mahsulot ta'rifi Asaxiy Books loyihasida yana bir PREMYERA - “Bog‘dagi uy” kitobi sotuvda! "
        .'Yoz keldi... Qishloqdagi eski bog‘da ikki oila yashaydi, ular bir-birini yillar davomida tanimay keladi. '
        .'Bir kuni bog‘ devori qulab tushadi va hamma narsa o‘zgaradi. Muallif bu voqeani sokin, ammo ta’sirli ohangda hikoya qiladi. '
        .'Kitobni o‘qir ekansiz, qahramonlar bilan birga kuzni, qishni va bahorni kutasiz. Har bir bob yangi bir sirni ochadi. '
        .'Kitob davomida: • qo‘shnichilik qadrini his qilasiz; • kechirim kuchini ko‘rasiz; • oddiy kunlarning go‘zalligini anglaysiz. '
        .'Nega biz yonimizdagi odamni eng oxirida taniymiz? Muallif bu savolga javob bermaydi, lekin uni o‘quvchining o‘ziga qoldiradi. '
        .'Hikoya so‘ngida har bir qahramon o‘z yo‘lini topadi. Kitob sizga: • sabr; • umid; • do‘stlik haqida so‘zlaydi. '
        ."Bu kitob butun oila uchun. Ko'proq ko'rish";

    public function test_a_flat_description_gets_its_paragraphs_and_list_items_back(): void
    {
        $this->assertSame(
            "Yoz keldi... Qishloqdagi eski bog'da ikki oila yashaydi, ular bir-birini yillar davomida tanimay keladi. "
            ."Bir kuni bog' devori qulab tushadi va hamma narsa o'zgaradi. Muallif bu voqeani sokin, ammo ta'sirli ohangda hikoya qiladi. "
            // Too little is left after this paragraph to make another.
            ."Kitobni o'qir ekansiz, qahramonlar bilan birga kuzni, qishni va bahorni kutasiz. Har bir bob yangi bir sirni ochadi.\n\n"
            ."Kitob davomida:\n"
            ."• qo'shnichilik qadrini his qilasiz;\n"
            ."• kechirim kuchini ko'rasiz;\n"
            ."• oddiy kunlarning go'zalligini anglaysiz.\n\n"
            ."Nega biz yonimizdagi odamni eng oxirida taniymiz? Muallif bu savolga javob bermaydi, lekin uni o'quvchining o'ziga qoldiradi. "
            ."Hikoya so'ngida har bir qahramon o'z yo'lini topadi.\n\n"
            ."Kitob sizga:\n"
            ."• sabr;\n"
            ."• umid;\n"
            ."• do'stlik haqida so'zlaydi.\n\n"
            .'Bu kitob butun oila uchun.',
            AsaxiyDescription::tidy(self::FLAT),
        );
    }

    public function test_only_text_is_moved_never_changed(): void
    {
        $tidy = AsaxiyDescription::tidy(self::FLAT);
        $squash = fn (string $text) => preg_replace('/\s+/u', '', UzbekText::normalizeApostrophes($text));

        $body = preg_replace(['/^.*?sotuvda! /u', "/ Ko'proq ko'rish$/u"], '', self::FLAT);

        $this->assertSame($squash($body), $squash($tidy));
        $this->assertSame($tidy, AsaxiyDescription::tidy($tidy), 'tidying twice changes nothing');
    }

    public function test_the_shops_announcement_is_dropped_but_a_mention_of_the_shop_is_not(): void
    {
        $this->assertSame('Ikkinchi gap.', AsaxiyDescription::tidy('“Asaxiy Books”dan yana bir muhim yangilik — Bo Bennetning “Mantiqiy xato. To‘g‘ri fikrlash” kitobi o‘zbek tilida! Ikkinchi gap.'));
        $this->assertSame('Ikkinchi gap.', AsaxiyDescription::tidy('“Yaralangan qush hamon uchmoqda” kitobi sotuvda! Ikkinchi gap.'));
        $this->assertSame('Ikkinchi gap.', AsaxiyDescription::tidy('Asaxiy Pro loyihasida ilk PREMYERA – “Maqsad” kitobi sotuvda! Ikkinchi gap.'));

        $founder = '"Tadbirkor" - "Asaxiy" internet-do‘koni asoschisi Firuz Allayevning tajribasi mahsulidir. Ikkinchi gap.';
        $this->assertSame(UzbekText::normalizeApostrophes($founder), AsaxiyDescription::tidy($founder));

        // Nothing would be left.
        $this->assertSame('Asaxiy Books loyihasida PREMYERA - “Maqsad” kitobi sotuvda!', AsaxiyDescription::tidy('Asaxiy Books loyihasida PREMYERA - “Maqsad” kitobi sotuvda!'));
    }

    public function test_sentences_do_not_end_at_an_initial_or_inside_quotes(): void
    {
        $text = 'Bu asar haqida ko‘p yozilgan va u bir necha tilga tarjima qilingan, '.str_repeat('juda ko‘p o‘quvchilar uni sevadi, ', 8)
            .'E. M. Goldratt va “Maqsad. Biznes roman” kitobi esa boshqa mavzu. Uchinchi gap hammasini yakunlaydi va bu gap yetarlicha uzun bo‘lishi kerak, '
            .'shunda paragraf ajratiladi, chunki qolgan matn yuz belgidan oshadi va bu yerda tugaydi.';

        $tidy = AsaxiyDescription::tidy($text);

        $this->assertStringContainsString('E. M. Goldratt va “Maqsad. Biznes roman” kitobi esa boshqa mavzu.', $tidy);
        $this->assertStringContainsString("boshqa mavzu.\n\nUchinchi gap", $tidy);
    }

    public function test_text_that_already_has_line_breaks_keeps_them(): void
    {
        $this->assertSame(
            "Birinchi qism.\nUshbu qator ham shunday qoladi.\n\nIkkinchi qism:\n• bir\n• ikki",
            AsaxiyDescription::tidy("Birinchi  qism.\r\nUshbu qator ham shunday qoladi.\n\n\n\nIkkinchi qism:\n\n• bir\n\n• ikki\n"),
        );
    }

    public function test_html_paragraphs_line_breaks_and_list_items_become_lines(): void
    {
        $html = '<p style="text-align: center;"><strong>Asaxiy Books loyihasida yana bir PREMYERA - &ldquo;Bog&lsquo;dagi uy&rdquo; kitobi&nbsp;sotuvda!</strong></p>
            <p><br />
            <em>Yoz keldi...</em><br />
            &nbsp;</p>
            <p>Qishloqdagi eski bog&lsquo;da ikki oila
            yashaydi.</p>
            <p><img src="x.png" /></p>
            <p><strong>Kitob davomida:</strong></p>
            <p>&bull; qo&lsquo;shnichilik qadrini his qilasiz;</p>
            <p>&bull; kechirim kuchini ko&lsquo;rasiz.</p>
            <p>&nbsp;</p>
            <ul><li>sabr</li><li>umid</li></ul>
            <p>Oxirgi gap.</p>';

        $this->assertSame(
            "Yoz keldi...\n\nQishloqdagi eski bog'da ikki oila yashaydi.\n\nKitob davomida:\n• qo'shnichilik qadrini his qilasiz;\n• kechirim kuchini ko'rasiz.\n• sabr\n• umid\n\nOxirgi gap.",
            AsaxiyDescription::fromHtml($html),
        );
    }

    public function test_the_product_page_description_is_read_without_its_button(): void
    {
        $page = '<div class="product__description"><h2>Mahsulot ta\'rifi</h2>
            <div class="description__item collapsed" id="description-content">
                <p>Birinchi.</p><div class="note"><p>Ichkarida.</p></div><p>&bull; band</p>
            </div>
            <button id="toggle-description" class="toggle-btn w-100">Ko\'proq ko\'rish</button></div>
            <div class="other"><p>Boshqa blok.</p></div>';

        $this->assertSame("Birinchi.\n\nIchkarida.\n\n• band", AsaxiyPage::parse($page)['description']);
        $this->assertNull(AsaxiyPage::parse('<html><h1>Kitob</h1></html>')['description']);
    }

    public function test_an_uzbek_listing_is_made_latin_and_keeps_its_lines(): void
    {
        $this->assertSame("Китоб ҳақида\nИкки", AsaxiyCatalog::description("Китоб ҳақида\nИкки"));
        $this->assertSame("Kitob haqida\nIkki", AsaxiyCatalog::description("Mahsulot ta'rifi Китоб ҳақида\nИкки", uzbek: true));
        $this->assertNull(AsaxiyCatalog::description("Mahsulot ta'rifi  "));
    }
}
