<?php

namespace App\Support\Asaxiy;

use App\Support\UzbekText;

/**
 * Asaxiy's "Mahsulot ta'rifi" as plain text the book page can show as it is
 * (it renders line breaks): paragraphs separated by a blank line, each "•"
 * item on a line of its own, and none of the shop's own wrapping — the
 * heading, the "Ko'proq ko'rish" button, and an opening announcement like
 * "Asaxiy Books loyihasida yana bir PREMYERA - “Hayot qadri” kitobi sotuvda!".
 *
 * The workbook the catalogue was imported from had already flattened every
 * description to a single line, so for such text the structure is guessed
 * back: a list item starts at each bullet and ends at its sentence, and long
 * prose is broken into paragraphs at sentence ends. A description read from
 * the product page itself (fromHtml) keeps the paragraphs it was written in.
 */
class AsaxiyDescription
{
    /** What Asaxiy's editors start a list item with ("·" in imported English and Russian blurbs). */
    private const BULLET = '[•·●▪◾✅✔📌🔹]';

    /** A paragraph of restored prose closes at the first sentence end after this many characters… */
    private const PARAGRAPH = 300;

    /** …unless what would be left of the prose is shorter than this. */
    private const SHORTEST_PARAGRAPH = 100;

    /** A sentence like "Kitob nima haqida?" or "Kimlar uchun foydali?" is a heading, and starts a paragraph. */
    private const HEADING = 60;

    /**
     * The description block of a product page, as text.
     */
    public static function fromHtml(string $html): ?string
    {
        // Line breaks in the source mean nothing; tags say where lines go.
        $html = preg_replace('/\s+/u', ' ', $html);
        $html = preg_replace('/<(script|style)\b.*?<\/\1>/i', '', $html);
        $html = preg_replace('/<br\b[^>]*>/i', "\n", $html);
        $html = preg_replace('/<li\b[^>]*>/i', "\n• ", $html);
        $html = preg_replace('/<\/?(p|div|li|ul|ol|h[1-6]|blockquote|table|tr)\b[^>]*>/i', "\n\n", $html);

        return self::tidy(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * A description from the workbook, the page, or already in the
     * catalogue: trimmed of the shop's wrapping, and given back its
     * paragraphs and list items if it arrives as one line.
     */
    public static function tidy(?string $text): ?string
    {
        $text = UzbekText::cleanParagraphs($text);

        if ($text === null) {
            return null;
        }

        $text = preg_replace("/^(Mahsulot ta'rifi|Tavsif|Описание)\s*/u", '', $text);
        $text = preg_replace("/\s*Ko'proq ko'rish$/u", '', $text);
        $text = self::withoutAnnouncement($text);

        $flat = ! str_contains($text, "\n");
        $lines = array_map(fn (string $line) => self::restore($line, paragraphs: $flat), explode("\n", $text));

        return self::compactLists(UzbekText::cleanParagraphs(implode("\n", $lines)));
    }

    /**
     * Drops an opening sentence that announces the listing rather than
     * describing the book: Asaxiy's "… PREMYERA … sotuvda!", "Asaxiy Books
     * loyihasidan yangi qadam, … oʻzbek tilida!", "“…” kitobi sotuvda!".
     */
    private static function withoutAnnouncement(string $text): string
    {
        $firstLine = strtok($text, "\n");
        $sentences = self::sentences($firstLine);
        $lead = $sentences[0];

        $announces = (preg_match('/asaxiy|асаксий/iu', $lead)
                && preg_match('/premyera|премьер|sotuvda|в продаже|yangilik|yangi qadam|navbatdagi|loyiha/iu', $lead))
            || preg_match('/(sotuvda|в продаже)!$/iu', $lead);

        if (! $announces || mb_strlen($lead) > 250 || trim(substr($text, strlen($lead))) === '') {
            return $text;
        }

        return ltrim(substr($text, strlen($lead)));
    }

    /**
     * One line of text as paragraphs and list items. With $paragraphs off
     * (the text already has its own line breaks), only list items are split
     * out.
     */
    private static function restore(string $line, bool $paragraphs): string
    {
        $segments = preg_split('/\s+(?='.self::BULLET.')/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        $blocks = [];

        foreach ($segments as $i => $segment) {
            if (! preg_match('/^'.self::BULLET.'/u', $segment)) {
                $blocks[] = ['prose', $segment];

                continue;
            }

            // "•Инвестирование" and "✔ Hayot": one space after the marker.
            $segment = preg_replace('/^('.self::BULLET.'\x{FE0F}?)\s*/u', '$1 ', $segment);
            $sentences = self::sentences($segment);
            $rest = trim(substr($segment, strlen($sentences[0])));

            // The item is its first sentence; what follows is the text after
            // the list, or the lead-in to the next one ("… Mutolaa sizga:").
            // Mid-list, a second sentence on its own is still the item's.
            $last = $i === count($segments) - 1;
            $endsList = $rest !== '' && ($last || count($sentences) > 2 || str_ends_with($rest, ':'));

            $blocks[] = ['item', $endsList ? $sentences[0] : $segment];

            if ($endsList) {
                $blocks[] = ['prose', $rest];
            }
        }

        $out = '';

        foreach ($blocks as $i => [$type, $text]) {
            $next = $blocks[$i + 1][0] ?? null;

            if ($type === 'prose') {
                $text = $paragraphs ? self::paragraphs($text, $next === 'item') : $text;
            }

            $out .= $text;

            if ($next !== null) {
                $out .= $next === 'item' && ($type === 'item' || self::leadsIn($text)) ? "\n" : "\n\n";
            }
        }

        return $out;
    }

    /**
     * Prose as paragraphs of a few sentences. A short question or a lead-in
     * to a list ("Kitob davomida:") starts a paragraph of its own.
     */
    private static function paragraphs(string $prose, bool $beforeList): string
    {
        $sentences = self::sentences($prose);
        $paragraphs = [];
        $current = '';

        foreach ($sentences as $i => $sentence) {
            $heading = (mb_strlen($sentence) <= self::HEADING && preg_match('/[?:]$/u', $sentence))
                || ($beforeList && $i === count($sentences) - 1 && str_ends_with($sentence, ':'));

            if ($current !== '' && $heading) {
                $paragraphs[] = $current;
                $current = '';
            }

            $current = ltrim("{$current} {$sentence}");
            $left = mb_strlen(implode(' ', array_slice($sentences, $i + 1)));

            if (mb_strlen($current) >= self::PARAGRAPH && $left >= self::SHORTEST_PARAGRAPH && ! str_ends_with($current, ':')) {
                $paragraphs[] = $current;
                $current = '';
            }
        }

        if ($current !== '') {
            $paragraphs[] = $current;
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * A line split at its sentence ends: ". ", "! ", "? ", "... " before a
     * capital letter, outside quotation marks, and not after an initial
     * ("M. Goldratt") or a common abbreviation. Joined with single spaces,
     * the pieces are the line again.
     *
     * @return non-empty-list<string>
     */
    private static function sentences(string $line): array
    {
        preg_match_all('/(?<=[.!?…])["”»]?(?= +["“«]?[\p{Lu}])/u', $line, $ends, PREG_OFFSET_CAPTURE);

        $sentences = [];
        $start = 0;

        foreach ($ends[0] as [$quote, $offset]) {
            $end = $offset + strlen($quote);
            $before = substr($line, 0, $end);

            if (self::insideQuotes($before, $line)
                || preg_match('/(?:^|[\s(])(\p{Lu}|Dr|Mr|Mrs|Ms|St|Jr|Sr|vs|т\. ?е|т\. ?д|т\. ?п|др|им|г|гг|св|см|рис|напр|h\.k|va h)\.["”»]?$/u', $before)) {
                continue;
            }

            $sentences[] = trim(substr($line, $start, $end - $start));
            $start = $end;
        }

        $sentences[] = trim(substr($line, $start));

        return array_values(array_filter($sentences, fn (string $s) => $s !== '')) ?: [$line];
    }

    /** Whether $before ends inside a quotation, counting only marks that pair up across the whole line. */
    private static function insideQuotes(string $before, string $line): bool
    {
        foreach ([['“', '”'], ['«', '»']] as [$open, $close]) {
            if (substr_count($line, $open) === substr_count($line, $close)
                && substr_count($before, $open) > substr_count($before, $close)) {
                return true;
            }
        }

        $straight = substr_count($line, '"');

        return $straight % 2 === 0 && substr_count($before, '"') % 2 === 1;
    }

    /** "Kitob davomida:", "Kimlar uchun foydali?": text the list under it answers. */
    private static function leadsIn(string $text): bool
    {
        $sentences = self::sentences($text);

        return str_ends_with($text, ':') || (str_ends_with($text, '?') && mb_strlen(end($sentences)) <= self::HEADING);
    }

    /** A list reads as one block: no blank line between its items, or after its lead-in. */
    private static function compactLists(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $item = fn (?string $line) => $line !== null && preg_match('/^'.self::BULLET.'/u', $line) === 1;
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $i => $line) {
            $previous = $kept === [] ? null : $kept[count($kept) - 1];

            if ($line === '' && $item($lines[$i + 1] ?? null) && $previous !== null && ($item($previous) || self::leadsIn($previous))) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
