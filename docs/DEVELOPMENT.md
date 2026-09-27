# Reads-admin development guide

Reads-admin is the Laravel and Filament application behind the public Reads
site. It owns all editable content and exposes a small public read API.

## Docker setup

Copy the example environment file and adjust local values if needed:

```sh
cp .env.example .env
```

The checked-out local setup uses MySQL through the Sail compose file:

```sh
docker compose up -d --build mysql laravel.test
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
```

The API should be published on host port 8000 when the Reads frontend uses its
default `API_URL`:

```dotenv
APP_PORT=8000
```

The admin panel is available at `http://localhost:8000/admin`.

## Verification

Use the PHP version supplied by the Docker image, not the host PHP binary:

```sh
docker compose exec laravel.test php -l app/Models/Book.php
docker compose exec laravel.test php artisan route:list --path=api
docker compose exec laravel.test php artisan test
```

If the Sail image is not built yet, PHP syntax can still be checked with a
supported PHP Docker image:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find app database routes -name "*.php" -print0 | xargs -0 -n1 php -l'
```

## Panel architecture

There is exactly **one** Filament panel (`app/Providers/Filament/AdminPanelProvider.php`,
id `admin`, path `/admin`), shared by every role. There is no separate
translator, taqrizchi, or publisher panel — a role-based render hook and
per-resource visibility rules make one panel behave like several:

- Every signed-in `User` is gated by `User::canAccessPanel()`
  (`app/Models/User.php`): an admin is always let in; a translator,
  taqrizchi, or publisher is let in only if their matching profile row
  (`translator()`, `taqrizchi()`, or `publisher()`) exists.
- A render hook in `AdminPanelProvider` hides the sidebar entirely for
  translators and publishers, and injects a topbar link to their dashboard
  instead. They get a "my profile" experience: a dashboard widget
  (`TranslatorProfileWidget`, `PublisherProfileWidget`) links to their one
  record, with everything else (language pairs, books, social links) reached
  from relation-manager tabs on that same edit page rather than separate nav
  entries. A taqrizchi keeps the sidebar — reviewing is a many-row workflow
  (`TaqrizResource`'s list, filters, and moderation status), not a single
  profile to edit, so it doesn't fit the same treatment; they still reach
  their own profile via `TaqrizchiProfileWidget`'s edit link, since
  `TaqrizchiResource` itself stays admin-only in the nav.
- `TaqrizResource` is the one resource genuinely reachable from a non-admin
  role's nav (a taqrizchi manages their own reviews there).
  `PublisherResource` also registers nav for a publisher
  (`shouldRegisterNavigation()`), but that's moot once their sidebar is
  hidden — they reach it via the dashboard widget instead, same as a
  translator and `TranslatorResource`. Every other resource
  (`AuthorResource`, `TranslatorResource`, `TaqrizchiResource`,
  `InquiryResource`) is admin-only in the nav.

## User roles

`users.role` is a `UserRole` enum (`app/Enums/UserRole.php`) with four
cases: `admin`, `translator`, `taqrizchi`, `publisher`. It defaults to
`translator` at the database level. A role only grants panel access once the
matching profile row exists and points at that user (`translator.user_id`,
`taqrizchilar.user_id`, or `publishers.user_id`) — creating a `User` alone is
not enough.

Only admin accounts are created via artisan (see `DEPLOY.md`,
`reads:create-admin`). Translator, taqrizchi, and publisher accounts are
created by an admin filling in the corresponding Filament resource's create
form, which generates a password with `Str::password(12)` and displays it
once.

## Content model

### Translators

`translators` own profiles, language pairs, social links, inquiries, and their
translator-facing book relationship.

`translators.user_id` is nullable: a translator row can exist without a
login ("claimed" only once an admin attaches an account, same idea as an
unclaimed publisher row). The main ways one gets created are inline — a
publisher crediting an edition's translator who has no profile here yet,
from the translators repeater in their own `Books we publish` form — and the
Asaxiy import, which creates one per translator name it finds.
That inline form only ever asks for a name; a unique `slug` is generated
from it (`Translator::generateUniqueSlug()`), and `user_id` stays null.
`TranslatorObserver` only enforces the admin-only-fields rule on an
*existing* record (`$translator->exists`) — creation is gated separately, by
`TranslatorPolicy` keeping the full `/admin/translators/create` page
admin-only and by this inline path never exposing `slug`/`email`/`password`
fields to begin with. An admin later turns a name-only row into a real
account from `TranslatorResource`'s edit page, the same way
`PublisherResource`'s edit page lets an admin add a login to an unclaimed
publisher.

### Taqrizchilar (reviewers)

`taqrizchilar` is the reviewer account type, shaped like `Translator` but
without years-active/language-pair/SEO-override fields: `user_id` (unique),
`slug`, localized `name`/`bio`/`title`/`location`, and `avatar_path`. A
taqrizchi writes `taqrizlar` (reviews) and has the same polymorphic social
links as a translator.

Admins manage reviewers from the Filament `Taqrizchilar` resource; a
taqrizchi manages only their own profile, reached from their dashboard
widget. `slug` and the login-account fields (`user_id`/email/password) are
admin-only — a non-admin write to them is silently reverted by
`TaqrizchiObserver`.

### Taqrizlar (reviews)

`taqrizlar` is one taqrizchi's review of one book: `body`, an optional
`youtube_url`, five optional decimal(3,1) scores (`score_plot`,
`score_style`, `score_translation`, `score_cover`, `score_overall` — see
`Taqriz::ASPECTS`), and a `status` (`TaqrizStatus`: pending/approved/
rejected, defaulting to pending). A reviewer may only ever save a review as
pending; only an admin can approve or reject it, from `TaqrizResource`'s
inline actions. Editing an already-approved review as anyone but an admin
demotes it back to pending (`TaqrizObserver`) so an edited review is never
live with unreviewed text.

`Taqriz::getAverageScoreAttribute()` averages only the aspects that were
actually filled in — `score_translation` is meaningless (and hidden in the
form) for a book with no translator, so it is excluded from that book's
average rather than counted as a zero.

A reviewer can create the `Book` they're reviewing inline, from
`TaqrizResource`'s form, if it doesn't already exist in the catalogue; that
book is created with `status = pending` and needs its own admin approval
before it (and the review) can appear on the public site.

One reviewer can only review a given book once (`unique(taqrizchi_id,
book_id)`).

### Publishers

`publishers` is the normalized catalogue of publishing houses: a unique
`name` and `slug`, localized `bio` and `country`, an optional
`established_year`, `logo_path`, and `website_url`. It backfills
`publisher_id` from the legacy book publisher string, which stays nullable
for compatibility.

Publishers are now also an optional **account type**: a nullable, unique
`publishers.user_id` lets a publisher log in and manage their own row and
its books, the same pattern as translators and taqrizchilar. A catalogue
row created only to normalize book data (no login) is unaffected — `user_id`
stays null until an admin adds a login account for that publisher.

Admins manage publishers from the Filament `Publishers` resource, which is
also visible to a logged-in publisher (scoped to their own row via
`getEloquentQuery()`). `slug` and the login-account fields are admin-only,
enforced by `PublisherObserver`.

Every edition form has a searchable `publisher_id` select with inline create
(`App\Filament\Support\CatalogueFields::publisherSelect()`), which is what
`PublisherPolicy::create()` being open to everyone is for. `PublisherObserver`
only guards `slug`/`user_id` on an *existing* row, so a non-admin creating a
publisher inline gets its generated slug.

### Authors

`authors` is the normalized catalogue of literary authors. Fields are:

- `name` and unique `slug`.
- Localized `bio` and `nationality` JSON values for `uz`, `ru`, and `en`.
- Optional `birth_year` and `death_year`.
- Optional `portrait_path` and `website_url`.

Admins manage authors from the Filament `Book authors` resource.
`AuthorPolicy::create()` is open to everyone, not just admins, because the
authors select on every "add a book" form creates rows inline
(`CatalogueFields::authorsSelect()`). `original_name` holds the name in its
own script ("Абдулла Қодирий") so editions in other languages can find it.

### Books and editions

A `books` row is the **work**; each printing of it (publisher, ISBN, year,
cover, translators, excerpt) is an `editions` row, and translators are
credited per edition through `edition_translator` with a role. Taqrizlar
belong to an edition. The full model, permissions and the Asaxiy import are
described in [DATA-MODEL.md](DATA-MODEL.md).

Where books are edited in the panel:

- **Admins:** the `Books` resource (the works, their shelves and series, and
  every edition underneath). Its nav badge counts pending books.
- **Translators:** `Books & translations` on their profile. Each row is one
  of their editions; the form starts by picking the book (or adding it).
- **Publishers:** `Books we publish`, also a list of editions, with a
  translators repeater.
- **Taqrizchilar:** pick the edition they read on the taqriz form, or add a
  pending book and edition inline.

`status` (`ModerationStatus`) exists on both books and editions. The site
shows a book when it is approved and has at least one approved edition.
`ModerationStatus` and `TaqrizStatus` share three cases but are deliberately
separate enums: a book and a review are moderated for different reasons.

## Public API

All read endpoints are throttled (120 requests/minute):

- `GET /api/translators?locale=uz`
- `GET /api/translators/{slug}?locale=uz`
- `GET /api/authors?locale=uz` — paginated; `q`, `sort=books|name`, `page`, `per_page`
- `GET /api/authors/{slug-or-id}?locale=uz`
- `GET /api/books` — paginated; `q`, `author`, `publisher`, `translator`, `category`, `series`, `sort=year|title|recent`, `page`, `per_page` (≤ 100)
- `GET /api/books/{id}` — with `editions`, `taqrizlar`, `relatedBooks`
- `GET /api/categories` — the shelf tree with book counts
- `GET /api/publishers?locale=uz` — paginated like authors
- `GET /api/publishers/{slug-or-id}?locale=uz`
- `GET /api/taqrizchilar?locale=uz`
- `GET /api/taqrizchilar/{slug}?locale=uz`
- `GET /api/taqrizlar/{id}`

Translator responses include `books[]`, one entry per edition they worked on.
Book rows carry the work plus the flat card fields of its primary edition;
see [DATA-MODEL.md](DATA-MODEL.md#the-api-contract). Author and publisher rows
carry `bookCount` and a few `coverUrls` for their cards.

`/api/taqrizchilar` eager-loads only that reviewer's *approved* reviews.
`/api/taqrizlar` has no index route, only a single-review permalink; it
returns 404 (not 403) for a review that isn't approved yet, so an
unpublished review's existence isn't revealed.

Inquiry submissions remain a separate, more tightly throttled endpoint:

- `POST /api/translators/{translator}/inquiries` (5 requests/minute)

## Migration notes

Run migrations after pulling:

```sh
docker compose exec laravel.test php artisan migrate
```

Two migrations are intended to preserve existing data by backfilling a new
normalized table from a legacy free-text column:

- The **author** migration creates one author per distinct legacy author
  name and links matching books via `author_id`. Do not remove the legacy
  `author` column until all external consumers have moved to `authorId`.
- The **publisher** migration does the same for `publisher_id`.

Everything from the taqrizchi/publisher-accounts work
(`2026_09_19_*` migrations) is additive: nullable columns and new tables, no
destructive changes to existing data.

The **editions** migrations (`2026_09_26_*`) are not additive: the second one
moves every book's publisher, year, cover, excerpt, translator and author
onto a new edition row (and taqrizlar onto that edition), then drops those
columns from `books`. Book ids are unchanged. Its `down()` folds them back,
and refuses once any book has two editions, an edition two translators, or a
book two authors. **Back up the production database before deploying it.**

## SEO revalidation

Saving (or deleting) a translator, taqrizchi, publisher, book, language pair,
or social link pings the public Next.js site so it drops its cached copy
immediately instead of waiting out its cache window — see
`app/Services/SiteRevalidator.php` and the five observers in
`app/Observers/` (`TranslatorObserver`, `TaqrizchiObserver`,
`PublisherObserver`, `TaqrizObserver`, `ProfileContentObserver`). It is
best-effort and configured entirely through `config/seo.php` — see
`DEPLOY.md` for the environment variables and troubleshooting.

## Admin workflow

1. Open Filament and create or edit an author in `Book authors`.
2. Open a translator profile and use the `Books & translations` relation.
3. Pick the book (search by title or author), or add it with its authors
   inline, then fill in the edition: publisher, ISBN, year, cover.
4. Upload covers, portraits, and avatars only as JPEG, PNG, or WebP.
5. To onboard a reviewer or a publisher account, create their record from
   the `Taqrizchilar` or `Publishers` resource — this generates and displays
   a one-time password for their login.
6. New books added by a reviewer, and any book still `pending`, need
   approval from `Books` (or inline from `Taqrizlar`) before they or their
   reviews appear on the public site.

Filament resources are auto-discovered from `app/Filament/Resources`.

## Frontend relationship

The related Next.js repository is `../reads`. Its local development guide is
at `../reads/docs/DEVELOPMENT.md`. The frontend expects this API on port 8000
and resolves relative media paths against that API origin.
