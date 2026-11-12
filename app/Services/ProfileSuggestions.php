<?php

namespace App\Services;

use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\ProfileSuggestion;
use App\Models\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Researched facts about authors and translators, loaded for review and
 * published by an admin, the way PublisherSuggestions works for
 * publishers. Nothing reaches a page until an admin presses Publish.
 */
class ProfileSuggestions
{
    public const LOCALES = ['uz', 'ru', 'en'];

    /** What each kind of page takes from a suggestion, beyond the bio. */
    public const AUTHOR_FIELDS = ['original_name', 'birth_year', 'death_year'];

    public const AUTHOR_TEXTS = ['bio', 'nationality'];

    public const TRANSLATOR_TEXTS = ['bio', 'title', 'location'];

    /**
     * Loads findings as pending suggestions. One still waiting is refreshed;
     * one already published or rejected is left alone, unless $reopen is set
     * and the file brings a bio it was not decided with.
     *
     * @param  list<array<string, mixed>>  $entries  Each with "type" (author or translator) and "slug".
     * @return array{created: list<string>, refreshed: list<string>, reopened: list<string>, decided: list<string>, empty: list<string>, missing: list<string>}
     */
    public function import(array $entries, bool $reopen = false): array
    {
        $report = ['created' => [], 'refreshed' => [], 'reopened' => [], 'decided' => [], 'empty' => [], 'missing' => []];

        foreach ($entries as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $slug = (string) ($entry['slug'] ?? '');
            $class = ProfileSuggestion::TYPES[$type] ?? null;
            $subject = $class !== null && $slug !== '' ? $class::query()->where('slug', $slug)->first() : null;
            $label = "{$type}:{$slug}";

            if ($subject === null) {
                $report['missing'][] = $label;

                continue;
            }

            $values = self::clean($entry);

            if (! self::hasDetails($values)) {
                $report['empty'][] = $label;

                continue;
            }

            $existing = ProfileSuggestion::query()
                ->where('subject_type', $class)
                ->where('subject_id', $subject->getKey())
                ->first();
            $reopening = false;

            if ($existing !== null && $existing->status !== ModerationStatus::Pending) {
                if (! $reopen || $values['bio'] === null || $values['bio'] == $existing->bio) {
                    $report['decided'][] = $label;

                    continue;
                }

                $existing->status = ModerationStatus::Pending;
                $existing->reviewed_at = null;
                $reopening = true;
            }

            $suggestion = $existing ?? new ProfileSuggestion(['subject_type' => $class, 'subject_id' => $subject->getKey()]);
            $suggestion->fill($values)->save();

            $report[$reopening ? 'reopened' : ($existing ? 'refreshed' : 'created')][] = $label;
        }

        return $report;
    }

    /**
     * Writes what the admin approved to the person's page and marks the
     * suggestion published. The form started from the page's own values
     * wherever it had them, so what comes in is the page as it should be.
     *
     * @param  array<string, mixed>  $data  The review form.
     */
    public function publish(ProfileSuggestion $suggestion, array $data): Model
    {
        return DB::transaction(function () use ($suggestion, $data) {
            $subject = $suggestion->subject;

            if ($subject instanceof Author) {
                foreach (self::AUTHOR_FIELDS as $field) {
                    $value = $data[$field] ?? null;
                    $subject->{$field} = filled($value) ? $value : null;
                }
            }

            foreach (self::texts($subject) as $attribute) {
                foreach (self::LOCALES as $locale) {
                    $text = trim((string) ($data[$attribute][$locale] ?? ''));

                    $text !== ''
                        ? $subject->setTranslation($attribute, $locale, $text)
                        : $subject->forgetTranslation($attribute, $locale);
                }
            }

            $subject->save();

            // Translators ping their own page on save; authors have no
            // observer, and their facts show on book pages too.
            if ($subject instanceof Author) {
                app(SiteRevalidator::class)->everything();
            }

            $suggestion->update(['status' => ModerationStatus::Approved, 'reviewed_at' => now()]);

            return $subject;
        });
    }

    /**
     * Publishes each waiting suggestion as its review would start: the
     * page's own values stay, the findings fill only what it lacks. One
     * site rebuild for the lot.
     *
     * @param  iterable<ProfileSuggestion>  $suggestions
     * @return int How many were published.
     */
    public function publishMany(iterable $suggestions): int
    {
        return SiteRevalidator::batch(function () use ($suggestions) {
            $count = 0;

            foreach ($suggestions as $suggestion) {
                if ($suggestion->status !== ModerationStatus::Pending || $suggestion->subject === null) {
                    continue;
                }

                $this->publish($suggestion, self::formState($suggestion));
                $count++;
            }

            return $count;
        });
    }

    public function reject(ProfileSuggestion $suggestion): void
    {
        $suggestion->update(['status' => ModerationStatus::Rejected, 'reviewed_at' => now()]);
    }

    /**
     * The review form's starting values: the page's own where it has one,
     * the finding where it has none.
     *
     * @return array<string, mixed>
     */
    public static function formState(ProfileSuggestion $suggestion): array
    {
        $subject = $suggestion->subject;
        $state = [];

        if ($subject instanceof Author) {
            foreach (self::AUTHOR_FIELDS as $field) {
                $state[$field] = filled($subject->{$field}) ? $subject->{$field} : $suggestion->{$field};
            }
        }

        foreach (self::texts($subject) as $attribute) {
            foreach (self::LOCALES as $locale) {
                $current = $subject->getTranslation($attribute, $locale, false);
                $state[$attribute][$locale] = filled($current) ? $current : ($suggestion->{$attribute}[$locale] ?? null);
            }
        }

        return $state;
    }

    /** @return list<string> */
    public static function texts(Model $subject): array
    {
        return $subject instanceof Author ? self::AUTHOR_TEXTS : self::TRANSLATOR_TEXTS;
    }

    /**
     * One entry of a findings file, with anything malformed dropped rather
     * than stored: a year that cannot be one, a source that is not http(s).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function clean(array $entry): array
    {
        $year = function (string $key) use ($entry): ?int {
            $value = (int) ($entry[$key] ?? 0);

            return $value >= 1 && $value <= (int) date('Y') ? $value : null;
        };
        $birth = $year('birth_year');
        $death = $year('death_year');

        if ($birth !== null && $death !== null && $death < $birth) {
            $birth = $death = null;
        }

        $name = trim((string) ($entry['original_name'] ?? ''));

        return [
            'original_name' => $name !== '' ? Str::limit($name, 255, '') : null,
            'birth_year' => $birth,
            'death_year' => $death,
            'nationality' => self::byLocale($entry['nationality'] ?? null, 100),
            'title' => self::byLocale($entry['title'] ?? null, 160),
            'location' => self::byLocale($entry['location'] ?? null, 160),
            'bio' => self::byLocale($entry['bio'] ?? null, 5000),
            'sources' => self::sources($entry['sources'] ?? null),
            'confidence' => in_array($entry['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $entry['confidence'] : null,
            'note' => filled($entry['note'] ?? null) ? Str::limit(trim((string) $entry['note']), 2000, '') : null,
        ];
    }

    /** @return array<string, string>|null */
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

    /** @return list<string>|null */
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
        foreach (['bio', 'birth_year', 'death_year', 'nationality', 'title', 'location'] as $field) {
            if (filled($values[$field])) {
                return true;
            }
        }

        return false;
    }
}
