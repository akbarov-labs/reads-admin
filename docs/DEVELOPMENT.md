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

## Content model

### Translators

`translators` own profiles, language pairs, social links, inquiries, and their
translator-facing book relationship.

### Authors

`authors` is the normalized catalogue of literary authors. Fields are:

- `name` and unique `slug`.
- Localized `bio` and `nationality` JSON values for `uz`, `ru`, and `en`.
- Optional `birth_year` and `death_year`.
- Optional `portrait_path` and `website_url`.

Admins manage authors from the Filament `Book authors` resource.

### Books

`books` remains associated with a translator and now has nullable `author_id`.
The migration keeps the old `author` string column for compatibility, creates
authors from existing non-empty values, and backfills `author_id`.

New books should use `author_id`. The old string is nullable and is only a
fallback for legacy records and API compatibility.

### Publishers

`publishers` is the normalized catalogue of publishing houses. It stores a
unique `name` and `slug`, localized `bio` and `country`, an optional
`established_year`, `logo_path`, and `website_url`. The publisher migration
backfills `publisher_id` from the existing book publisher string while keeping
that string nullable for compatibility.

Admins manage publishers from the Filament `Publishers` resource. New books
select a publisher from the searchable publisher field.

## Public API

All read endpoints are throttled:

- `GET /api/translators?locale=uz`
- `GET /api/translators/{slug}?locale=uz`
- `GET /api/authors?locale=uz`
- `GET /api/authors/{id}?locale=uz`
- `GET /api/books`
- `GET /api/books/{id}`
- `GET /api/publishers?locale=uz`
- `GET /api/publishers/{id}?locale=uz`

Translator responses include nested books. Book responses include `authorId`
and the resolved author name. Catalogue book responses also include the linked
translator slug and name.

## Migration notes

Run the author migration after the existing books migration:

```sh
docker compose exec laravel.test php artisan migrate
```

The migration is intended to preserve existing data. It creates one author per
distinct legacy author name and links matching books. Do not remove the legacy
`author` column until all external consumers have moved to `authorId`.

## Admin workflow

1. Open Filament and create or edit an author in `Book authors`.
2. Open a translator profile and use the `Books & translations` relation.
3. Select an existing author or create one from the author field.
4. Upload covers and portraits only as JPEG, PNG, or WebP.

Filament resources are auto-discovered from `app/Filament/Resources`.

## Frontend relationship

The related Next.js repository is `../reads`. Its local development guide is
at `../reads/docs/DEVELOPMENT.md`. The frontend expects this API on port 8000
and resolves relative media paths against that API origin.