<?php

namespace App\Support\Asaxiy;

/**
 * Reads what an asaxiy.uz product page says about a book: the
 * "Xususiyatlar" table, the genre in the breadcrumb, the cover and gallery
 * photos, the "Tavsiya" block of related books, and the description with
 * its paragraphs and lists (the workbook has it on one line).
 *
 * Kept free of HTTP so it can be tested against saved HTML, and so the
 * scraper can cache the parsed result instead of the 600 KB page.
 */
class AsaxiyPage
{
    /**
     * The shelves worth recording, by listing path. Publisher collections
     * ("Asaxiy Books kitoblari"), language shelves ("Rus tilidagi kitoblar")
     * and shop states ("Oldindan buyurtma") are left out: the edition
     * already says who published it and in what language.
     *
     * @var array<string, array{name: string, parent: ?string}>
     */
    public const CATEGORIES = [
        'badiy-adabiyot' => ['name' => 'Badiiy adabiyot', 'parent' => null],
        'badiy-adabiyot/zhahon-adabiyoti' => ['name' => 'Jahon adabiyoti', 'parent' => 'badiy-adabiyot'],
        'badiy-adabiyot/uzbek-adabiyoti' => ["name" => "O'zbek adabiyoti", 'parent' => 'badiy-adabiyot'],
        'tureckaya-literatura' => ['name' => 'Turk adabiyoti', 'parent' => 'badiy-adabiyot'],
        'detektiv' => ['name' => 'Detektiv va fantastika', 'parent' => null],
        'psixologiya' => ['name' => 'Psixologiya va shaxsiy rivojlanish', 'parent' => null],
        'biznes' => ['name' => 'Biznes', 'parent' => null],
        'siyosat' => ['name' => 'Siyosat va iqtisodiyot', 'parent' => null],
        'biography' => ['name' => 'Biografiya', 'parent' => null],
        'istoriya' => ['name' => 'Tarix', 'parent' => null],
        'dinij-adabiyot' => ['name' => 'Diniy adabiyot', 'parent' => null],
        'bolalar-adabiyoti' => ['name' => 'Bolalar adabiyoti', 'parent' => null],
        'ilm-fan-va-darsliklar' => ["name" => "O'quv adabiyoti", 'parent' => null],
        'ilm-fan-va-darsliklar/it-sohasiga-oid-kitoblar' => ['name' => 'IT', 'parent' => 'ilm-fan-va-darsliklar'],
        'ilm-fan-va-darsliklar/izuchenie-inostrannyh-yazykov' => ["name" => "Chet tilini o'rganish", 'parent' => 'ilm-fan-va-darsliklar'],
        'ilm-fan-va-darsliklar/abiturient' => ['name' => 'Abituriyentlar uchun', 'parent' => 'ilm-fan-va-darsliklar'],
        'ilm-fan-va-darsliklar/lugatlar' => ["name" => "Lug'at va so'zlashgichlar", 'parent' => 'ilm-fan-va-darsliklar'],
        'bestseller' => ['name' => 'TOP-100 bestseller', 'parent' => null],
        'dlya-kollekcionerov' => ['name' => 'Kolleksionerlar uchun', 'parent' => null],
        'podarochnye-nabory' => ["name" => "Sovg'a to'plamlari", 'parent' => null],
    ];

    /**
     * @return array{
     *     title: ?string,
     *     specs: array<string, string>,
     *     breadcrumb: ?string,
     *     cover: ?string,
     *     gallery: list<string>,
     *     related: list<string>,
     *     description: ?string,
     * }
     */
    public static function parse(string $html): array
    {
        return [
            'title' => self::title($html),
            'specs' => self::specs($html),
            'breadcrumb' => self::breadcrumb($html),
            'cover' => self::cover($html),
            'gallery' => self::gallery($html),
            'related' => self::related($html),
            'description' => self::description($html),
        ];
    }

    /**
     * Product links on a category listing page, in page order.
     *
     * @return list<string> absolute product URLs
     */
    public static function listingProducts(string $html): array
    {
        preg_match_all('/class="[^"]*product__item[^"]*".*?href="(\/product\/[a-z0-9-]+)"/s', $html, $m);

        return array_values(array_unique(array_map(fn (string $path) => 'https://asaxiy.uz'.$path, $m[1])));
    }

    /**
     * "Xususiyatlar": label => value, e.g. "Betlar soni" => "744".
     *
     * @return array<string, string>
     */
    private static function specs(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        $specs = [];

        foreach ($rows[1] as $row) {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/s', $row, $cells);
            $cells = array_map(self::text(...), $cells[1]);

            if (count($cells) === 2 && $cells[0] !== '' && $cells[1] !== '' && mb_strlen($cells[1]) < 300) {
                $specs[$cells[0]] = $cells[1];
            }
        }

        return $specs;
    }

    /** The crumb between "Bosh sahifa" and the product itself: its main shelf. */
    private static function breadcrumb(string $html): ?string
    {
        if (! preg_match('/breadcrumb.*?<\/(?:ol|ul)>/s', $html, $block)) {
            return null;
        }

        preg_match_all('/<(?:a|span)[^>]*>([^<]+)<\/(?:a|span)>/', $block[0], $crumbs);
        $crumbs = array_values(array_filter(array_map(self::text(...), $crumbs[1])));

        // [Bosh sahifa, <shelf>, <product title>]
        return count($crumbs) >= 3 ? $crumbs[count($crumbs) - 2] : null;
    }

    private static function title(string $html): ?string
    {
        return preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m) ? (self::text($m[1]) ?: null) : null;
    }

    private static function cover(string $html): ?string
    {
        preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m);

        $url = isset($m[1]) ? html_entity_decode($m[1]) : null;

        // The site falls back to its own logo when a product has no photo.
        return $url !== null && ! str_contains($url, '/logos/') ? $url : null;
    }

    /**
     * The product's own photo carousel. The page also carries thumbnails of
     * other products (in "Tavsiya", in the header), so only the block before
     * the recommendations is read.
     *
     * @return list<string>
     */
    private static function gallery(string $html): array
    {
        $end = strpos($html, 'carousel-recommends-product');
        $own = $end === false ? $html : substr($html, 0, $end);

        preg_match_all('/https:\/\/assets\.asaxiy\.uz\/product\/items\/desktop\/[^"\'\s)]+/', $own, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return list<string> absolute product URLs Asaxiy recommends alongside this one */
    private static function related(string $html): array
    {
        $start = strpos($html, 'carousel-recommends-product');

        if ($start === false) {
            return [];
        }

        $block = substr($html, $start, 200_000);
        $stop = strpos($block, '</section>');
        $block = $stop === false ? $block : substr($block, 0, $stop);

        preg_match_all('/href="(?:https:\/\/asaxiy\.uz)?(\/product\/[a-z0-9-]{3,})"/', $block, $m);

        return array_values(array_unique(array_map(
            fn (string $path) => 'https://asaxiy.uz'.$path,
            array_filter($m[1], fn (string $path) => ! str_starts_with($path, '/product/knigi')),
        )));
    }

    /**
     * "Mahsulot ta'rifi": the block the page collapses behind its
     * "Ko'proq ko'rish" button, which is left out.
     */
    private static function description(string $html): ?string
    {
        if (! preg_match('/<div[^>]*\bid="description-content"[^>]*>/', $html, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        preg_match_all('/<(\/?)div\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE, $start);

        foreach ($tags[1] as $i => [$slash]) {
            $depth += $slash === '' ? 1 : -1;

            if ($depth === 0) {
                return AsaxiyDescription::fromHtml(substr($html, $start, $tags[0][$i][1] - $start));
            }
        }

        return null;
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }
}
