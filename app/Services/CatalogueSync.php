<?php

namespace App\Services;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Models\Book;
use App\Models\Edition;
use App\Support\Asaxiy\AsaxiyCatalog;
use App\Support\BotGenres;
use App\Support\UzbekText;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two-way sync of the moderator-approved catalogue with the Kitoblarim
 * Telegram bot (docs/DATA-MODEL.md, "Syncing with the Kitoblarim bot").
 *
 * push() takes a book the bot's moderators approved and merges it into the
 * catalogue by ISBN; feed() hands the bot what changed here since it last
 * looked. Both talk about an edition through projection(), the handful of
 * fields the two sides share — it is what the feed serves and what the
 * loop-guard hash is taken over, so the two cannot drift apart.
 *
 * pushBook() is the Kitob Marafoni bot's way in for a book with no ISBN:
 * it finds the book or starts one awaiting review, and syncs nothing back.
 */
class CatalogueSync
{
    public const UZBEK = "O'zbek tili";

    /**
     * The codes the bot sends, as the labels the catalogue already stores
     * (AsaxiyCatalog writes "{language} tili"). Anything else is free text.
     *
     * @var array<string, string>
     */
    private const LANGUAGES = [
        'uz' => self::UZBEK,
        'ru' => 'Rus tili',
        'en' => 'Ingliz tili',
        'tr' => 'Turk tili',
        'fr' => 'Fransuz tili',
        'de' => 'Nemis tili',
        'ar' => 'Arab tili',
        'es' => 'Ispan tili',
        'zh' => 'Xitoy tili',
        'ja' => 'Yapon tili',
        'kaa' => 'Qoraqalpoq tili',
    ];

    public function __construct(
        private readonly CatalogueRecords $records,
        private readonly SiteRevalidator $revalidator,
    ) {}

    /** Digits and X only, upper case; null unless that is a 13-digit ISBN. */
    public static function normalizeIsbn(string $raw): ?string
    {
        $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw));

        // The isbn column is 13 characters. ISBN-10s are refused for now
        // rather than converted: the catalogue has never held one.
        return preg_match('/^\d{13}$/', $isbn) === 1 ? $isbn : null;
    }

    // ------------------------------------------------------------------
    // Push: bot → here
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  the validated request body
     * @return array{outcome: string, bookId: int, editionId: int, updatedAt: string}
     */
    public function push(string $isbn, array $data): array
    {
        $incoming = $this->normalise($isbn, $data);

        // A title that was only invisible characters validates as present
        // and cleans to nothing.
        if ($incoming['title'] === null) {
            throw ValidationException::withMessages(['title' => 'The title is required.']);
        }

        try {
            $result = $this->write($isbn, $incoming);
        } catch (UniqueConstraintViolationException) {
            // Two pushes of one new ISBN raced and the other got there
            // first: this time the edition exists, so it merges.
            $result = $this->write($isbn, $incoming);
        }

        ['shelved' => $shelved] = $result;
        unset($result['shelved']);

        // One ping for the book, after the commit. The writes ran without
        // model events (see write()) so a push is not several pings.
        if ($result['outcome'] !== 'unchanged' || $shelved) {
            $this->revalidator->book($result['bookId']);
        }

        return $result;
    }

    /**
     * What the bot sent, cleaned the way the catalogue is: one apostrophe,
     * single spaces, and Latin script for Uzbek-language text.
     *
     * @param  array<string, mixed>  $data
     * @return array{isbn: string, title: ?string, authors: list<string>, translators: list<string>, publisher: ?string, language: ?string, year: ?int, pages: ?int, description: ?string, genre: ?string, updatedAt: CarbonImmutable, fillOnly: bool}
     */
    private function normalise(string $isbn, array $data): array
    {
        $language = $this->language($data['language'] ?? null);
        $uzbek = $language === self::UZBEK;

        $text = fn (mixed $value, bool $paragraphs = false): ?string => $this->text($value, $uzbek, $paragraphs);
        $names = fn (mixed $list): array => $this->names($list, $uzbek);

        $publisher = $text($data['publisher'] ?? null);

        // Asaxiy's "other publishers" and a bare number are not publishers.
        if ($publisher !== null && (ctype_digit($publisher) || in_array(UzbekText::publisherKey($publisher), AsaxiyCatalog::NO_PUBLISHER, true))) {
            $publisher = null;
        }

        return [
            'isbn' => $isbn,
            'title' => $text($data['title'] ?? null),
            'authors' => $names($data['authors'] ?? []),
            'translators' => $names($data['translators'] ?? []),
            'publisher' => $publisher,
            'language' => $language,
            // An implausible year arrives as no year, so it never replaces
            // a real one (a blank never erases anything, see merge()).
            'year' => isset($data['year']) ? Edition::plausibleYear((int) $data['year']) : null,
            'pages' => isset($data['pages']) ? (int) $data['pages'] : null,
            'description' => $text($data['description'] ?? null, paragraphs: true),
            // Only ever a shelf, never stored as text (see shelve()).
            'genre' => is_string($data['genre'] ?? null) ? UzbekText::clean($data['genre']) : null,
            // Whole seconds, like the column it is compared with.
            'updatedAt' => CarbonImmutable::parse($data['updatedAt'])->setMicrosecond(0),
            'fillOnly' => (bool) ($data['fillOnly'] ?? false),
        ];
    }

    /** Cleaned, and made Latin when the text is Uzbek; null when nothing is left. */
    // A description keeps its paragraphs; everything else is one line.
    private function text(mixed $value, bool $uzbek, bool $paragraphs = false): ?string
    {
        $value = ! is_string($value) ? null : ($paragraphs ? UzbekText::cleanParagraphs($value) : UzbekText::clean($value));

        return $value !== null && $uzbek && UzbekText::hasCyrillic($value) ? UzbekText::toLatin($value) : $value;
    }

    /** @return list<string> */
    private function names(mixed $list, bool $uzbek): array
    {
        $names = [];

        foreach (is_array($list) ? $list : [] as $name) {
            $name = $this->text($name, $uzbek);

            // Two spellings of one person in one credit line are one credit.
            if ($name !== null && UzbekText::key($name) !== '') {
                $names[UzbekText::key($name)] ??= $name;
            }
        }

        return array_values($names);
    }

    private function language(mixed $value): ?string
    {
        $value = is_string($value) ? UzbekText::clean($value) : null;

        if ($value === null) {
            return null;
        }

        // "uz", "UZ", "uz-Latn" and "uz_UZ" are all Uzbek.
        $code = strtolower(preg_split('/[-_]/', $value)[0]);

        return self::LANGUAGES[$code] ?? $value;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array{outcome: string, bookId: int, editionId: int, updatedAt: string, shelved: bool}
     */
    private function write(string $isbn, array $incoming): array
    {
        // Without events: Edition, Book and Publisher observers each ping
        // the public site, which for a book with three credits is a handful
        // of calls. push() sends the one that matters.
        return Model::withoutEvents(fn () => DB::transaction(function () use ($isbn, $incoming) {
            $edition = Edition::with('book')->where('isbn', $isbn)->first();

            if ($edition === null) {
                $edition = $this->create($incoming);
                $outcome = 'created';
            } else {
                $outcome = $this->merge($edition, $incoming);
            }

            $edition->load(['book.authors', 'publisher', 'translators']);

            // Rejected here: nothing was written, and the feed never serves
            // it, so there is nothing to guard.
            $agreed = $edition->status !== ModerationStatus::Rejected;

            $shelved = $agreed && $this->shelve($edition->book, $incoming['genre']);

            // Stamp the loop guard only when the bot and this app now agree.
            // If the stored edition differs from what was sent — this side
            // was newer, or kept values the bot left blank, or knows the
            // author under another spelling — the bot has not seen the
            // stored version yet, and hiding it from the feed would hide it
            // for good.
            $stored = $this->projection($edition);
            $sent = $this->canonical($incoming);

            if ($agreed && $this->hash($stored) === $this->hash($sent)) {
                Edition::whereKey($edition->id)->toBase()->update([
                    'sync_origin' => 'bot',
                    'sync_hash' => $this->hash($stored),
                    'synced_at' => now(),
                ]);
            }

            return [
                'outcome' => $outcome,
                'bookId' => $edition->book_id,
                'editionId' => $edition->id,
                'updatedAt' => self::iso($edition->updated_at),
                'shelved' => $shelved,
            ];
        }));
    }

    /**
     * Puts a book Reads has on no shelf yet on the shelf of the bot's genre,
     * as its primary one. A book already on a shelf keeps exactly what it
     * has: shelves are curated here. The genre is not part of the
     * projection, so this leaves the loop guard alone.
     */
    private function shelve(Book $book, ?string $genre): bool
    {
        $shelf = $genre !== null ? (new BotGenres)->shelf($genre) : null;

        if ($shelf === null || $book->categories()->exists()) {
            return false;
        }

        $book->categories()->attach($shelf->id, ['is_primary' => true]);

        return true;
    }

    /**
     * A new ISBN: join the book it belongs to, or start one.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function create(array $incoming): Edition
    {
        $firstAuthor = $incoming['authors'][0] ?? null;
        $book = $this->records->findBook($firstAuthor, $incoming['title']);

        if ($book === null) {
            $book = Book::create([
                'uzbek_title' => $incoming['title'],
                // As in the seeder: an untranslated book is in its own language.
                'source_language' => $incoming['translators'] === [] && $incoming['language'] !== null
                    ? explode(',', $incoming['language'])[0]
                    : null,
                'status' => ModerationStatus::Approved,
            ]);

            foreach ($incoming['authors'] as $position => $name) {
                $book->authors()->syncWithoutDetaching([$this->records->author($name)->id => ['position' => $position]]);
            }
        }

        $edition = $book->editions()->create([
            'publisher_id' => $incoming['publisher'] !== null ? $this->records->publisher($incoming['publisher'])->id : null,
            'title' => $this->editionTitle($book, $incoming['title']),
            'isbn' => $incoming['isbn'],
            'language' => $incoming['language'],
            'year' => $incoming['year'],
            'pages' => $incoming['pages'],
            'description' => $incoming['description'],
            'status' => ModerationStatus::Approved,
        ]);

        $this->replaceTranslators($edition, $incoming['translators']);

        return $edition;
    }

    /**
     * An existing ISBN. Whichever side changed it last wins, but only with
     * values it actually has: a blank never erases anything. When the bot's
     * copy is older it can still fill what we have left empty. The first
     * time the bot sends a book (fillOnly), it counts as older whatever its
     * timestamp: neither side's values were edits made against the other's.
     *
     * Cover, gallery, taqrizlar, series and the book itself (its title and
     * authors) are never touched from here; shelves only through shelve().
     *
     * @param  array<string, mixed>  $incoming
     */
    private function merge(Edition $edition, array $incoming): string
    {
        // A moderator here rejected it; the bot does not get to undo that.
        if ($edition->status === ModerationStatus::Rejected) {
            return 'unchanged';
        }

        $newer = ! $incoming['fillOnly']
            && ($edition->updated_at === null || $incoming['updatedAt']->greaterThan($edition->updated_at));
        $changed = false;

        // Approved in the bot, so approved here.
        if ($edition->status !== ModerationStatus::Approved) {
            $edition->status = ModerationStatus::Approved;
            $changed = true;
        }

        foreach (['language', 'year', 'pages', 'description'] as $field) {
            if ($incoming[$field] !== null && ($newer || blank($edition->{$field}))) {
                $edition->{$field} = $incoming[$field];
            }
        }

        if ($incoming['publisher'] !== null && ($newer || $edition->publisher_id === null)) {
            $edition->publisher_id = $this->records->publisher($incoming['publisher'])->id;
        }

        // An edition's own title is "as printed", so a title that only
        // differs in spelling from the book's is not one. And an older
        // push never retitles: a blank title here means "same as the
        // book", which is an answer, not a gap.
        if ($newer && $incoming['title'] !== null) {
            $edition->title = $this->editionTitle($edition->book, $incoming['title']);
        }

        $changed = $edition->isDirty() || $changed;

        $credited = $this->translatorIds($edition);

        if ($incoming['translators'] !== [] && ($newer || $credited === [])) {
            $changed = $this->replaceTranslators($edition, $incoming['translators']) || $changed;
        }

        if ($changed) {
            // save() writes nothing when only the credit list moved, and the
            // feed orders by updated_at, so the touch is what announces it.
            $edition->isDirty() ? $edition->save() : $edition->touch();
        }

        return match (true) {
            ! $changed => 'unchanged',
            $newer => 'updated',
            default => 'enriched',
        };
    }

    /** null when the edition's title is the book's own. */
    private function editionTitle(Book $book, string $title): ?string
    {
        return UzbekText::key($title) === UzbekText::key($book->uzbek_title) ? null : $title;
    }

    /** @return list<int> */
    private function translatorIds(Edition $edition): array
    {
        return $edition->credits()->where('role', BookRole::Translator->value)->pluck('translator_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Makes the translators the edition's translator credits, in order.
     * Other roles on the edition (editor, author) are left alone.
     *
     * @param  list<string>  $names
     * @return bool whether the credit list changed
     */
    private function replaceTranslators(Edition $edition, array $names): bool
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[$this->records->translator($name)->id] = true;
        }

        $ids = array_keys($ids);

        if ($ids === $this->translatorIds($edition)) {
            return false;
        }

        $edition->credits()->where('role', BookRole::Translator->value)->delete();

        foreach ($ids as $position => $id) {
            $edition->translators()->attach($id, ['role' => BookRole::Translator->value, 'position' => $position]);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Push without an ISBN: the Kitob Marafoni bot → here
    // ------------------------------------------------------------------

    /**
     * A book a marathon admin added that has no ISBN. Found by first author
     * plus title, like create(); otherwise started **pending**, so an editor
     * looks at it before the site shows it. The same book sent again finds
     * the one made the first time, whatever its status now: a book an editor
     * rejected is not made again.
     *
     * A new book gets one approved edition with no ISBN, carrying the
     * language and description, so approving the book alone publishes it
     * (the site lists books that have an approved edition). A book that was
     * found is left exactly as it is.
     *
     * @param  array<string, mixed>  $data  the validated request body
     * @return array{outcome: string, bookId: int, status: string}
     */
    public function pushBook(array $data): array
    {
        $language = $this->language($data['language'] ?? null);
        $uzbek = $language === self::UZBEK;
        $title = $this->text($data['title'] ?? null, $uzbek);
        $authors = $this->names($data['authors'] ?? [], $uzbek);

        if ($title === null || UzbekText::key($title) === '') {
            throw ValidationException::withMessages(['title' => 'The title is required.']);
        }

        // Books have no unique key to lean on, so two sends of one new book
        // at once take turns: the second then finds what the first made.
        $lock = 'catalogue-sync-book:'.sha1(CatalogueRecords::bookKey($authors[0] ?? null, $title));

        return Cache::lock($lock, 10)->block(10, fn () => Model::withoutEvents(fn () => DB::transaction(function () use ($title, $authors, $language, $uzbek, $data) {
            $book = $this->records->findBook($authors[0] ?? null, $title);
            $outcome = 'found';

            if ($book === null) {
                $book = Book::create(['uzbek_title' => $title, 'status' => ModerationStatus::Pending]);

                foreach ($authors as $position => $name) {
                    $book->authors()->syncWithoutDetaching([$this->records->author($name)->id => ['position' => $position]]);
                }

                $book->editions()->create([
                    'language' => $language,
                    'description' => $this->text($data['description'] ?? null, $uzbek, paragraphs: true),
                    'status' => ModerationStatus::Approved,
                ]);

                $outcome = 'created';
            }

            // No ping for the public site: a new book is pending, and a
            // found one was not touched.
            return ['outcome' => $outcome, 'bookId' => $book->id, 'status' => $book->status->value];
        })));
    }

    // ------------------------------------------------------------------
    // Pull: here → bot
    // ------------------------------------------------------------------

    /**
     * One page of approved, ISBN-bearing editions in (updated_at, id) order,
     * after the given position, minus those the bot already has.
     *
     * `next` is where the last row *scanned* sits, skipped or not, whenever
     * the page was full, so a page of nothing but skipped rows still moves
     * the client on. It is null on the last page.
     *
     * @return array{items: list<array<string, mixed>>, next: ?array{since: string, after: int}}
     */
    public function feed(?CarbonInterface $since, ?int $after, int $limit): array
    {
        $query = Edition::query()
            ->with(['book.authors', 'book.categories', 'publisher', 'translators'])
            ->whereNotNull('editions.isbn')
            ->where('editions.status', ModerationStatus::Approved)
            ->whereHas('book', fn ($book) => $book->where('books.status', ModerationStatus::Approved))
            ->orderBy('editions.updated_at')
            ->orderBy('editions.id')
            ->limit($limit);

        if ($since !== null) {
            // The column holds app-timezone strings and a Carbon is bound
            // as its own wall clock, so convert before comparing.
            $since = Carbon::instance($since)->setTimezone(config('app.timezone'))->setMicrosecond(0);

            $query->where(fn ($page) => $page
                ->where('editions.updated_at', '>', $since)
                ->orWhere(fn ($tie) => $tie->where('editions.updated_at', $since)->where('editions.id', '>', $after ?? 0)));
        }

        $rows = $query->get();
        $items = [];

        foreach ($rows as $edition) {
            $projection = $this->projection($edition);

            if ($edition->sync_hash !== null && hash_equals($edition->sync_hash, $this->hash($projection))) {
                continue;
            }

            $primary = $edition->book->categories->first(fn ($category) => (bool) $category->pivot->is_primary);

            $items[] = [
                ...$projection,
                'genre' => $primary?->name,
                'coverUrl' => $edition->cover_url !== null ? rtrim((string) config('app.url'), '/').$edition->cover_url : null,
                'updatedAt' => self::iso($edition->updated_at),
            ];
        }

        $last = $rows->last();

        return [
            'items' => $items,
            'next' => $rows->count() >= $limit && $last !== null
                ? ['since' => self::iso($last->updated_at), 'after' => $last->id]
                : null,
        ];
    }

    // ------------------------------------------------------------------
    // The shared projection
    // ------------------------------------------------------------------

    /**
     * The fields both sides keep about an edition, as stored here. The
     * edition must have book.authors, publisher and translators loaded.
     *
     * @return array{isbn: ?string, title: ?string, authors: list<string>, translators: list<string>, publisher: ?string, language: ?string, year: ?int, pages: ?int, description: ?string}
     */
    public function projection(Edition $edition): array
    {
        return $this->canonical([
            'isbn' => $edition->isbn,
            'title' => $edition->title ?: $edition->book->uzbek_title,
            'authors' => $edition->book->authors->pluck('name')->all(),
            'translators' => $edition->translators
                ->filter(fn ($translator) => $translator->pivot->role === BookRole::Translator)
                ->sortBy(fn ($translator) => $translator->pivot->position)
                ->pluck('name')
                ->all(),
            'publisher' => $edition->publisher?->name,
            'language' => $edition->language,
            'year' => $edition->year,
            'pages' => $edition->pages,
            'description' => $edition->description,
        ]);
    }

    /** SHA-1 of the projection's canonical JSON; 40 characters, as the column is. */
    public function hash(array $projection): string
    {
        return sha1(json_encode($this->canonical($projection), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Fixed key order and trimmed strings, empty becoming null, so the same
     * edition always hashes the same whichever side's data it came from.
     *
     * @param  array<string, mixed>  $fields
     * @return array{isbn: ?string, title: ?string, authors: list<string>, translators: list<string>, publisher: ?string, language: ?string, year: ?int, pages: ?int, description: ?string}
     */
    private function canonical(array $fields): array
    {
        $string = function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return $value === '' ? null : $value;
        };
        $list = fn (mixed $values): array => array_values(array_filter(
            array_map($string, is_array($values) ? $values : []),
            fn (?string $value) => $value !== null,
        ));
        $number = fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        return [
            'isbn' => $string($fields['isbn'] ?? null),
            'title' => $string($fields['title'] ?? null),
            'authors' => $list($fields['authors'] ?? []),
            'translators' => $list($fields['translators'] ?? []),
            'publisher' => $string($fields['publisher'] ?? null),
            'language' => $string($fields['language'] ?? null),
            'year' => $number($fields['year'] ?? null),
            'pages' => $number($fields['pages'] ?? null),
            'description' => $string($fields['description'] ?? null),
        ];
    }

    /** UTC with a Z, so the value survives being put in a query string unescaped. */
    public static function iso(CarbonInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
