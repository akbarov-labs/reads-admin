<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads posts from a public Telegram channel without a Telegram account,
 * through its web preview: https://t.me/s/<channel> is HTML with the 20
 * latest posts, and ?before=<id> pages back from a post (checked
 * 2026-10-24). Only t.me is ever asked, and covers only from Telegram's own
 * CDN, so a pasted link cannot point the server anywhere else.
 */
class TelegramChannel
{
    /** A public username: 5–32 letters, digits and underscores, starting with a letter (4 for a few bought ones). */
    public const USERNAME = '/^[A-Za-z][A-Za-z0-9_]{3,31}$/';

    private const USER_AGENT = 'Mozilla/5.0 (compatible; KitoblarimBot/1.0; +https://kitoblarim.uz)';

    private const MAX_COVER_BYTES = 5 * 1024 * 1024;

    private const COVER_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * The channel and post id in a post link: t.me/<channel>/<id>, also
     * through /s/, telegram.me, without the scheme, or with ?single and
     * friends. A private channel's t.me/c/... link is not one: its posts
     * cannot be read without an account.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parseLink(string $link): ?array
    {
        $link = trim($link);

        if (! preg_match('#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/(?:s/)?([^/?\#]+)/(\d{1,12})(?:[/?\#].*)?$#i', $link, $m)) {
            return null;
        }

        return preg_match(self::USERNAME, $m[1]) ? [$m[1], (int) $m[2]] : null;
    }

    /** An "@name", "t.me/name" or bare name as a username, or null. */
    public static function username(?string $value): ?string
    {
        $value = trim((string) $value);
        $value = preg_replace('#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/(?:s/)?#i', '', $value);
        $value = ltrim(rtrim($value, '/'), '@');

        return preg_match(self::USERNAME, $value) ? $value : null;
    }

    /** One post, read from the page of posts up to it. */
    public function post(string $channel, int $id): TelegramPost
    {
        foreach ($this->page($channel, $id + 1) as $post) {
            if ($post->id === $id && strcasecmp($post->channel, $channel) === 0) {
                return $post;
            }
        }

        throw new TelegramChannelException("Post {$id} is not on @{$channel}'s public page. It may have been deleted, or it is one photo of an album: paste the link to the album's first photo.");
    }

    /**
     * The posts on one page of a channel's web preview, oldest first.
     *
     * @return list<TelegramPost>
     */
    public function page(string $channel, ?int $before = null): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions(['allow_redirects' => false])
                ->timeout(15)
                ->get("https://t.me/s/{$channel}", array_filter(['before' => $before]));
        } catch (ConnectionException) {
            throw new TelegramChannelException('Telegram did not answer. Try again in a minute.');
        }

        // t.me sends a channel with no public preview (private, a person, a
        // bot, or no such name) to its plain page instead.
        if ($response->redirect()) {
            throw new TelegramChannelException("@{$channel} has no public web preview: it is private, not a channel, or does not exist.");
        }

        if (! $response->successful()) {
            throw new TelegramChannelException("Telegram answered {$response->status()} for @{$channel}.");
        }

        return self::parsePage($response->body());
    }

    /** @return list<TelegramPost> */
    public static function parsePage(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $posts = [];

        foreach ($xpath->query('//div[@data-post and contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message ")]') as $node) {
            /** @var DOMElement $node */
            if (! preg_match('#^([A-Za-z0-9_]+)/(\d+)$#', $node->getAttribute('data-post'), $m)) {
                continue;
            }

            $text = $xpath->query('.//div[contains(@class, "js-message_text")]', $node)->item(0);
            $photo = $xpath->query('.//a[contains(@class, "tgme_widget_message_photo_wrap")]/@style | .//i[contains(@class, "tgme_widget_message_video_thumb")]/@style', $node)->item(0);
            $time = $xpath->query('.//time[@datetime]/@datetime', $node)->item(0);

            $posts[] = new TelegramPost(
                channel: $m[1],
                id: (int) $m[2],
                text: $text ? self::text($document, $text) : null,
                photoUrl: $photo && preg_match("#url\\('([^']+)'\\)#", $photo->nodeValue, $url) ? $url[1] : null,
                postedAt: $time ? CarbonImmutable::parse($time->nodeValue) : null,
            );
        }

        return $posts;
    }

    /**
     * A post's text with its line breaks. Emoji are images with the
     * character inside, so stripping the tags keeps them.
     */
    private static function text(DOMDocument $document, DOMElement $node): ?string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        $text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+\n/", "\n", str_replace("\u{00A0}", ' ', $text)));

        return $text === '' ? null : $text;
    }

    /**
     * Saves a post's cover on the public disk, so the site does not depend
     * on Telegram's short-lived CDN links. Null when there is none, or it is
     * not an image from Telegram.
     */
    public function saveCover(TelegramPost $post): ?string
    {
        $host = strtolower((string) parse_url((string) $post->photoUrl, PHP_URL_HOST));

        if ($host === '' || ! (str_ends_with($host, '.telesco.pe') || $host === 'telegram.org' || str_ends_with($host, '.telegram.org'))) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(20)->get($post->photoUrl);
        } catch (ConnectionException) {
            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! $response->successful() || ! isset(self::COVER_TYPES[$type]) || strlen($response->body()) > self::MAX_COVER_BYTES) {
            return null;
        }

        $path = 'announcements/'.Str::random(32).'.'.self::COVER_TYPES[$type];
        Storage::disk('public')->put($path, $response->body());

        return $path;
    }
}
