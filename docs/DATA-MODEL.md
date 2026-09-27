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
