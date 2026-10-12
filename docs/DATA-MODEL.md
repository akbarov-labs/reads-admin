# Catalogue data model: books and editions

Written for: developers working on reads-admin or on the Reads site that reads its API.

## The shape

```
authors ──< book_author >── books ──< editions >── edition_translator >── translators
                             │  │        │  │
            categories ──< book_category │  └── publishers
                             │           └──< edition_images
                   series ───┘           └──< taqrizlar >── taqrizchilar
                             books ──< book_relations >── books
```

**A book is the work**: what a reader means by "*O'tkan kunlar*". It carries
what every printing has in common: its title, its authors (in credit order),
the language it was written in, a description, its shelves, and its place in
a series.

**An edition is one printing** of that book: this publisher, this ISBN, this
year, this cover, translated by these people. *Mehrobdan chayon* from six
publishers is one book with six editions, not six books.

| Question | Where the answer lives |
|---|---|
| Who wrote it? | `book_author` (a book can have several authors) |
| Who translated it? | `edition_translator`, per edition, with a `role` (translator, editor, author) and credit `position` |
| Who published it, when, what ISBN? | the edition |
| What does the cover look like? | the edition (`cover_path`, plus `edition_images` for the gallery) |
| What genre is it? | `book_category` (`is_primary` marks the genre; other rows are other shelves, e.g. "TOP-100 bestseller") |
| Is it part of a series? | `books.series_id` + `series_position` |
| What should I read after it? | `book_relations` (imported from Asaxiy's "Tavsiya"), then series, author, genre |
| Which translation did a reviewer read? | `taqrizlar.edition_id` |

## Why taqrizlar hang off editions

A taqriz scores *Tarjima sifati* (translation quality) and *Muqova va nashr
sifati* (cover and print quality). Both belong to one specific printing, not
to the work. A reviewer may review two translations of the same book
separately. The unique key is `(taqrizchi_id, edition_id)`. The book page
shows the reviews of all its editions (`Book::taqrizlar()`, hasManyThrough).

## Who can edit what

- **Edition:** admins; anyone credited on it (their translator profile is
  attached); and the publisher that printed it. See `EditionPolicy`.
- **Book:** admins, or a non-admin who owns *every* edition of it. Once
  somebody else's edition exists, renaming the work would rename their book
  too, so it takes an admin. See `BookPolicy`.

## Moderation

Both books and editions have a `status`. The public site shows a book when
the book is approved **and** at least one of its editions is. A taqrizchi
adding a book in order to review it creates both as pending. Translators and
publishers create approved rows, as before.

## The API contract

`GET /api/books` is paginated (`?page`, `?per_page` ≤ 100) and filterable by
slug (`author`, `publisher`, `translator`, `category`, `series`) and by `q`
(title, either spelling of an author, or an ISBN in any format). Each row
keeps the flat card fields the site always read (`coverUrl`, `publisher`,
`year`, `translator` …). They are taken from the book's *primary edition*:
approved, with a cover if any has one, then the newest (`Book::primary_edition`).

`GET /api/books/{id}` adds `editions[]`, `taqrizlar` and `relatedBooks[]`.

`GET /api/translators/{slug}` keeps `books[]` as before, but each entry is
now one **edition** the translator worked on (`editionId` says which), so two
translations of one book by the same person show as two portfolio entries.

## Importing from Asaxiy

```sh
php artisan asaxiy:categories            # which shelves each book is on (~30 min)
php artisan asaxiy:scrape                # every product page + cover (a few hours)
php artisan asaxiy:scrape --gallery      # optional: gallery photos (~a day, ~4 GB)
php artisan db:seed --class=AsaxiyBooksSeeder
```

- The workbook is `storage/app/public/asaxiy_books.xlsx` (`ASAXIY_BOOKS_FILE`).
- Scraped pages are cached as JSON under `storage/app/asaxiy/`
  (`ASAXIY_CACHE_DIR`), so re-runs and the seeder work offline.
- Images go to `storage/app/public/downloads/` as `<isbn>.webp`, or
  `<article>.webp` when a listing has no valid ISBN; gallery photos are
  `<isbn>-2.webp`, `<isbn>-3.webp` …
- Every command resumes where it stopped. Each backs off and then stops if
  asaxiy.uz stops answering, since the site does rate-limit.
- The seeder is idempotent: an edition is matched by ISBN (or article
  number), and a second run only fills empty columns and attaches newly
  downloaded images. It never overwrites an admin's edits.

What the seeder normalises (`App\Support\UzbekText`, `App\Support\Asaxiy\AsaxiyCatalog`):

- Uzbek-language rows are transliterated from Cyrillic to Latin, and every
  apostrophe variant (`‘ ’ ʻ ʼ` and backtick) becomes `'`. Russian, English and
  other editions keep their spelling. A Cyrillic title that Asaxiy mistakenly
  tagged "O'zbekcha" but that is plainly Russian (ы, щ, ь and none of ў қ ғ ҳ)
  is treated as Russian.
- Publishers are merged by a key that ignores alphabet, case, punctuation and
  "nashr / nashriyoti / books / publishing" words: *Hilol nashr*,
  *HILOL-Nashr* and *Ҳилол-нашр* are one publisher. "Бошқа нашриётлар"
  ("other publishers") is treated as no publisher.
- The title prefix "Author: Title" is removed. So are listing notes such as
  "(2023)", "(qattiq)", "(kirill)" or the publisher's own name in brackets.
- Listings with the same first author and title become editions of one book,
  including a book somebody already entered by hand.
- "(Часть 2)", "3-kitob", "2-qism" and "Vol. 4" mark a series position. A
  series is created only when at least two books share it.

Known limits: editions in different languages ("Большая игра" and
"Katta o'yin") are not recognised as one work, and there is no merge tool
yet. Different spellings of one foreign author ("Fyodor Dostoyevskiy",
"F.M.Dostoyevskiy") stay separate authors until an admin merges them.


## Syncing with the Kitoblarim bot

The Kitoblarim Telegram bot keeps its own moderator-approved catalogue. Two
endpoints keep it level with this one, both under `/api/sync`, both needing
`Authorization: Bearer <token>`, where the token is `CATALOGUE_SYNC_TOKEN`
(`config/catalogue.php`). With no token configured they answer
`503 {"message":"Catalogue sync is not configured"}`; a missing or wrong
token is `401`. They are throttled at 120 requests a minute and are not part
of the public read API. The code is `App\Services\CatalogueSync`, which
finds or creates authors, publishers, translators and books through
`App\Services\CatalogueRecords`, the same keys the Asaxiy seeder uses.

**What both sides share** (the *projection*): `isbn`, `title` (the edition's
own, else the book's), `authors` (the book's, in order), `translators` (the
edition's translator credits, in order), `publisher`, `language`, `year`,
`pages`, `description`. Nothing else crosses: covers, galleries, taqrizlar,
series and shelves stay here.

### Push: `PUT /api/sync/editions/{isbn}`

```json
{ "title": "…", "authors": ["…"], "translators": ["…"], "publisher": "…" ,
  "language": "uz", "year": 2019, "pages": 300, "description": "…",
  "updatedAt": "2026-03-01T10:00:00Z" }
```

`title` and `updatedAt` are required; everything else may be null or empty.
`"fillOnly": true` means the bot has never synced this book: its copy is
then treated as **not newer** whatever its `updatedAt`, so it only fills
blanks here. Timestamps from before the sync say when a row was imported,
not which side is right; after the first sync, the newer edit wins.
The answer is `{"outcome", "bookId", "editionId", "updatedAt"}`, where
`updatedAt` is the edition's `updated_at` and `outcome` is one of:

- `created`: no edition had this ISBN. The book is found by *first author +
  title* (alphabet-insensitive, as for Asaxiy) or created approved; authors
  are attached in order; publisher and translators are found or created
  (translators as account-less shells); the edition is created approved.
- `updated`: the edition existed and the bot's `updatedAt` is **newer** than
  the edition's `updated_at`. The bot's non-blank values overwrite language,
  year, pages, description, publisher and the edition's own title (only if it
  differs from the book's, otherwise the edition's title is cleared), and
  the translator list is replaced. A blank never erases anything.
- `enriched`: the bot's copy is **not newer** (or `fillOnly`), but this side had blanks (or
  was pending) that it could fill. Only empty columns are filled; titles
  never change.
- `unchanged`: nothing to do.

Other rules:

- **ISBN** is stripped to digits and `X`. It must be 13 digits, else `422`;
  ISBN-10 is refused for now (the column holds 13 characters and the
  catalogue has never held one).
- **language**: `uz`, `ru`, `en` (also `tr fr de ar es zh ja kaa`) become the
  labels already stored ("O'zbek tili", "Rus tili", "Ingliz tili" …). Any
  other text is stored as given.
- **Text** goes through `UzbekText::clean`, so apostrophes are normalised and
  whitespace is collapsed (descriptions lose their line breaks, as Asaxiy's
  do). When the language is Uzbek, Cyrillic is transliterated to Latin.
- An existing book is never renamed and its authors never change.
- A pending edition becomes approved by a push (a moderator approved it in
  the bot). A **rejected** edition is left alone and answers `unchanged`.
- The writes run without model events and the public site is pinged once,
  for the book, when something changed.

### Pull: `GET /api/sync/editions?since=&after=&limit=`

Approved editions with an ISBN, on approved books, in `(updated_at, id)`
order. `since` (ISO-8601) and `after` (an edition id) are the keyset: rows
with `updated_at > since`, or `updated_at = since` and `id > after`. `limit`
is 1 to 500 (default 200). Leave both off to start from the beginning (the
bot's first backfill is the same call). Each item is the projection plus
`genre` (the book's primary shelf), `coverUrl` (absolute, or null) and
`updatedAt`. `next` is `{"since", "after"}` for the last row *scanned* when
the page was full, otherwise `null`; keep calling until it is `null`. A page
whose rows were all filtered out (below) has no items but still has a `next`.
Send `since` URL-encoded; timestamps we return end in `Z`, so there is no `+`
to lose.

Only changes to the edition row move it in this order, so a rename of an
author or publisher, or a change to the credit list alone, does not by
itself bring an edition back into the feed.

### The loop guard

`editions` has three nullable columns for this: `sync_origin` (`bot`),
`sync_hash` (40 characters) and `synced_at`. After a push, if the stored
edition's projection equals what the bot sent, the SHA-1 of its canonical
JSON is stored in `sync_hash`. The feed skips any edition whose current
projection still hashes to its `sync_hash`, so the bot is not handed back
what it just pushed; an admin edit to a synced field changes the hash and
the edition reappears. If the stored edition *differs* from what was sent
(this side was newer, kept values the bot left blank, or knows the author
under another spelling), the hash is not stamped, so the bot picks up the
stored version on its next pull. The bot should not push what it has just
pulled.
