<?php

namespace App\Services;

use App\Enums\ModerationStatus;
use App\Filament\Resources\PublisherResource;
use App\Models\Publisher;
use App\Models\PublisherSuggestion;
use App\Services\Telegram\TelegramChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Details found for publishers, loaded for review and published by an
 * admin. Unlike publishers:import-profiles (checked before it shipped, and
 * filling blank fields straight away), nothing here reaches the site until
 * an admin has looked at it and pressed Publish.
 */
class PublisherSuggestions
{
    public const LOCALES = ['uz', 'ru', 'en'];

    public function __construct(private readonly TelegramChannel $telegram) {}

    /**
     * Loads found details as pending suggestions. A suggestion still waiting
     * is refreshed; one already published or rejected is left alone, unless
     * $reopen is set and the file brings an About text or motto it was not decided
     * with: then it waits for review again.
     * Accepts a list of objects with a "slug", or an object keyed by slug.
     *
     * @param  array<int|string, array<string, mixed>>  $entries
     * @return array{created: list<string>, refreshed: list<string>, reopened: list<string>, decided: list<string>, empty: list<string>, missing: list<string>}
     */
    public function import(array $entries, bool $logos = true, bool $reopen = false): array
    {
        $report = ['created' => [], 'refreshed' => [], 'reopened' => [], 'decided' => [], 'empty' => [], 'missing' => []];

        foreach ($entries as $key => $entry) {
            $slug = (string) (is_string($key) ? $key : ($entry['slug'] ?? ''));
            $publisher = $slug !== '' ? (new Publisher)->resolveRouteBinding($slug) : null;

            if ($publisher === null) {
                $report['missing'][] = $slug;

                continue;
            }

            $existing = PublisherSuggestion::query()->where('publisher_id', $publisher->id)->first();

            $values = self::clean((array) $entry);
            $reopening = false;

            if ($existing !== null && $existing->status !== ModerationStatus::Pending) {
                $newText = ($values['bio'] !== null && $values['bio'] != $existing->bio)
                    || ($values['motto'] !== null && $values['motto'] != $existing->motto);

                if (! $reopen || ! $newText) {
                    $report['decided'][] = $publisher->slug;

                    continue;
                }

                $existing->status = ModerationStatus::Pending;
                $existing->reviewed_at = null;
                $reopening = true;
            }

            if (! self::hasDetails($values)) {
                $report['empty'][] = $publisher->slug;

                continue;
            }

            $suggestion = $existing ?? new PublisherSuggestion(['publisher_id' => $publisher->id]);
            $channelChanged = $suggestion->telegram_channel !== $values['telegram_channel'];
            $suggestion->fill($values);

            if ($logos && $values['telegram_channel'] !== null && ($channelChanged || blank($suggestion->logo_path))) {
                $suggestion->logo_path = $this->telegram->saveChannelPhoto($values['telegram_channel'], 'publisher-suggestions');
            } elseif ($values['telegram_channel'] === null) {
                $suggestion->logo_path = null;
            }

            $suggestion->save();
            $report[$reopening ? 'reopened' : ($existing ? 'refreshed' : 'created')][] = $publisher->slug;
        }

        return $report;
    }

    /**
     * Writes what the admin approved to the publisher's page, and marks the
     * suggestion published. The form showed the publisher's current value
     * wherever it had one, so what comes in is the page as it should be.
     *
     * @param  array<string, mixed>  $data  The review form: the publisher fields, bio by locale, and use_logo.
     */
    public function publish(PublisherSuggestion $suggestion, array $data): Publisher
    {
        return DB::transaction(function () use ($suggestion, $data) {
            $publisher = $suggestion->publisher;

            foreach (['telegram_channel', 'order_contact', 'shop_url', 'website_url', 'phone', 'instagram', 'address', 'established_year'] as $field) {
                $value = $data[$field] ?? null;
                $publisher->{$field} = filled($value) ? $value : null;
            }

            foreach (['bio', 'motto'] as $attribute) {
                foreach (self::LOCALES as $locale) {
                    $text = trim((string) ($data[$attribute][$locale] ?? ''));

                    $text !== ''
                        ? $publisher->setTranslation($attribute, $locale, $text)
                        : $publisher->forgetTranslation($attribute, $locale);
                }
            }

            if (($data['use_logo'] ?? false) && $suggestion->logo_path && Storage::disk('public')->exists($suggestion->logo_path)) {
                $logo = 'publishers/'.Str::random(32).'.'.pathinfo($suggestion->logo_path, PATHINFO_EXTENSION);
                Storage::disk('public')->copy($suggestion->logo_path, $logo);
                $publisher->logo_path = $logo;
            }

            $publisher->save();

            $suggestion->update(['status' => ModerationStatus::Approved, 'reviewed_at' => now()]);

            return $publisher;
        });
    }

    public function reject(PublisherSuggestion $suggestion): void
    {
        $suggestion->update(['status' => ModerationStatus::Rejected, 'reviewed_at' => now()]);
    }

    /**
     * The review form's starting values: the publisher's own where it has
     * one, the suggestion's where it has none.
     *
     * @return array<string, mixed>
     */
    public static function formState(PublisherSuggestion $suggestion): array
    {
        $publisher = $suggestion->publisher;
        $state = [];

        foreach (['telegram_channel', 'order_contact', 'shop_url', 'website_url', 'phone', 'instagram', 'address', 'established_year'] as $field) {
            $state[$field] = filled($publisher->{$field}) ? $publisher->{$field} : $suggestion->{$field};
        }

        foreach (['bio', 'motto'] as $attribute) {
            foreach (self::LOCALES as $locale) {
                $current = $publisher->getTranslation($attribute, $locale, false);
                $state[$attribute][$locale] = filled($current) ? $current : ($suggestion->{$attribute}[$locale] ?? null);
            }
        }

        $state['use_logo'] = $suggestion->logo_path !== null && blank($publisher->logo_path);

        return $state;
    }

    /**
     * One entry of a found-details file, with anything malformed dropped
     * rather than stored: a channel or Instagram that is not a username, a
     * website that is not http(s), a year that cannot be one.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function clean(array $entry): array
    {
        $text = fn (string $key, int $max) => filled($entry[$key] ?? null) ? Str::limit(trim((string) $entry[$key]), $max, '') : null;
        $website = $text('website_url', 255);
        $year = (int) ($entry['established_year'] ?? 0);
        $bio = self::byLocale($entry['bio'] ?? null, 5000);
        $motto = self::byLocale($entry['motto'] ?? null, 160);

        return [
            'telegram_channel' => TelegramChannel::username($entry['telegram_channel'] ?? null),
            'subscribers' => is_numeric($entry['subscribers'] ?? null) ? (int) $entry['subscribers'] : null,
            'order_contact' => TelegramChannel::username($entry['order_contact'] ?? null),
            'shop_url' => PublisherResource::shopUrl($entry['shop_url'] ?? null),
            'website_url' => $website !== null && preg_match('#^https?://[^\s/]+\.[^\s]+$#i', $website) ? $website : null,
            'phone' => $text('phone', 32),
            'instagram' => PublisherResource::instagramUsername($entry['instagram'] ?? null),
            'address' => $text('address', 255),
            'established_year' => $year >= 1800 && $year <= (int) date('Y') ? $year : null,
            'bio' => $bio,
            'motto' => $motto,
            'about_sources' => self::sources($entry['about_sources'] ?? null),
            'confidence' => in_array($entry['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $entry['confidence'] : null,
            'evidence' => $text('evidence', 2000),
            'other_channels' => $text('other_channels', 2000),
            'note' => $text('note', 2000),
        ];
    }

    /**
     * A text by locale, empty ones dropped, or null when none is left.
     *
     * @return array<string, string>|null
     */
    private static function byLocale(mixed $value, int $max): ?array
    {
        $texts = [];

        foreach (self::LOCALES as $locale) {
            $text = trim((string) (is_array($value) ? ($value[$locale] ?? '') : ''));

            if ($text !== '') {
                $texts[$locale] = Str::limit($text, $max, '');
            }
        }

        return $texts === [] ? null : $texts;
    }

    /**
     * Up to ten http(s) addresses an About text was written from.
     *
     * @return list<string>|null
     */
    private static function sources(mixed $value): ?array
    {
        $urls = array_values(array_filter(
            is_array($value) ? $value : [],
            fn ($url) => is_string($url) && strlen($url) <= 500 && preg_match('#^https?://[^\s/]+\.[^\s]+$#i', $url),
        ));

        return $urls === [] ? null : array_slice($urls, 0, 10);
    }

    /** @param  array<string, mixed>  $values */
    private static function hasDetails(array $values): bool
    {
        foreach (['telegram_channel', 'order_contact', 'shop_url', 'website_url', 'phone', 'instagram', 'address', 'established_year', 'bio', 'motto'] as $field) {
            if (filled($values[$field])) {
                return true;
            }
        }

        return false;
    }
}
