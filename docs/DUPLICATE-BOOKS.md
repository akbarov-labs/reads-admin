> **Superseded (2026-09-26).** The split this document proposed has been
> built the other way round: `books` is now the work and a new `editions`
> table holds each printing, with translators many-to-many per edition. See
> [DATA-MODEL.md](DATA-MODEL.md). Kept for the reasoning it records.

# Duplicate books across translators

Written for: whoever decides the content model for `reads-admin` next.

## The question

`books.translator_id` is a foreign key, so a book row belongs to at most one
translator. (It became nullable in the 2026_09_19 taqrizchi migrations so a
reviewer can add a book with no translator to review it; that's an
orthogonal change and doesn't affect the question below, which is only about
rows that *do* have a translator.) When two translators have both worked on
*The Old Man and the Sea*, the database holds two rows, and `/api/books` —
the site-wide catalogue behind the homepage's "Books & translations"
section — returns both. Should those be merged into one shared record, the
way author and publisher names were merged in the 2026_09_14 migrations?

## Recommendation: keep them translator-specific

Do not merge. The two rows are not duplicates of each other in any sense that
matters.

A row in `books` is not a book. It is **one person's edition of one book** —
it carries `uzbek_title`, `publisher_id`, `year`, `cover_path`, `role`, and an
`excerpt_source_text` / `excerpt_translated_text` pair that is, literally, that
translator's prose. Two translators working from the same original produce
different Uzbek titles, sell to different publishers in different years, and
have entirely different excerpts. Merging them would have to pick one of each
and discard the rest, which deletes the very thing a translator's portfolio
exists to show. `ExcerptReader` on the profile page renders that excerpt
side-by-side with the source: it has no meaning detached from its translator.

This is the opposite of the author/publisher case, and the difference is worth
being explicit about. "Ernest Hemingway" carries no per-translator
information — it is the same string no matter who typed it, so two rows for it
were a genuine duplicate and the backfill was right to collapse them. An
edition carries almost nothing *but* per-translator information.

## What is actually missing

There is a real modelling gap, and it is not deduplication — it is that
nothing in the schema says these two editions descend from the same original
work. `original_title` is free text on each row, so the site cannot answer
"who else has translated this?", and two spellings of the same title will
never line up.

The shape that fits, if that question ever needs answering:

```
works                       books (editions)
  id                          work_id      -> works.id, nullable
  slug                        translator_id
  original_title              uzbek_title, publisher_id, year,
  author_id   -> authors.id   cover_path, role, excerpt_*
  original_language
```

That is the same move the catalogue migrations already made, applied one level
up: the shared, translator-independent facts (original title, author, source
language) move to their own table; everything edition-specific stays where it
is. It backfills the same way — group existing rows by
`(author_id, original_title)`, create a `works` row per group, point the books
at it — and it is additive, so no existing row or API field has to change.

## What to do now

Nothing, unless one of these becomes true:

- **A second translator is added and the catalogue looks wrong.** Right now
  there is one translator, so no title appears twice and the homepage list has
  no visible duplicates. Judge the display problem when it exists; the fix may
  be as small as grouping `BookGrid` by original title.
- **The site needs "other translations of this book".** That is the feature
  that actually requires `works`. Build the table when the feature is
  specified, not before — a `works` row with one edition and no page to appear
  on is a join nobody reads.

Until then, the duplication is honest: two translators, two translations, two
entries, each credited to its own translator by
`CatalogBookResource`'s `translator` field.
