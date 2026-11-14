# Description audit — November 2026

Read-only snapshot of production Reads-admin, taken 14 Nov 2026 (03:43 UTC).
It uses the same rule as `Book::getAboutAttribute`: a book shows its own
description, or else the longest approved edition text of at least 100
characters that isn't a shop sales line. Page views cover the last 90 days.

The rewrite list is in `description-priority-2026-11.csv`.

## The short version

1. **90% of the descriptions Reads shows are copied from Asaxiy.** Only 63
   books (0.7%) have a text of our own. Google already has every Asaxiy
   text on asaxiy.uz, a bigger and older site, so on 8,500 pages our body text
   is a duplicate and Google has little reason to rank us over the shop.
2. **The copied texts are mostly readable.** The problem is that they aren't
   ours, not that they're bad. Cleaning them up (emoji, prices) would not make
   them original.
3. **Reads gets very little traffic:** 4,412 book-page views in 90 days (~49 a
   day), spread over 1,689 books. The top 200 books get half of all views. So a
   few hundred good texts will matter more than 9,000 so-so ones.
4. **38% of books (5,677) show nothing at all**, including 3 of the 10 most
   viewed books.

## What each book page shows

| Shows | Books | Share | Views (90 d) |
|---|---:|---:|---:|
| Copied from Asaxiy (credited with a link) | 8,494 | 56% | 2,248 |
| Edition text with no source (bot / publisher channels) | 845 | 6% | 223 |
| Our own description | 63 | 0.4% | 593 |
| Nothing | 5,677 | 38% | 1,348 |
| **All approved books** | **15,079** | | **4,412** |

Our 63 own texts get 593 views, about 9 views per book against 0.3 for an
Asaxiy copy. That's partly because we wrote for well-known books on
purpose, but it's the right direction.

The 845 texts with no source mostly came in through the bot and publisher
channels: Islamic publisher blurbs, short notes. Most are copied too, just
from Telegram posts instead of a shop.

## Language

| Source | Uzbek (Latin) | Russian | English | Uzbek (Cyrillic) | Unclear |
|---|---:|---:|---:|---:|---:|
| Asaxiy copy | 3,247 | 3,394 | 1,656 | 9 | 188 |
| Bot / channel | 758 | 28 | 37 | 0 | 22 |
| Own | 60 | 0 | 0 | 2 | 1 |

The language usually matches the edition: a Russian book carries a Russian
text. About 300 don't match. Examples: Uzbek editions with Russian or English
blurbs, Turkish editions with Uzbek text, English readers with Russian text.

Page views by site language: Uzbek 61%, Russian 20%, English 19%. **39% of
visits land on the Russian or English page**, where the description is
whatever language the copied text happens to be in.

## Problems inside the texts

Simple pattern checks, so these counts are approximate.

| Problem | Books | Notes |
|---|---:|---|
| Same text on several books | 1,253 (511 groups) | Series blurbs: one "Family and Friends" text on 17 books, "Super Sam" on 18, one children's-series blurb on 16 |
| Same opening, different ending | 272 | Mostly the same series blurbs |
| Under 200 characters | 253 | e.g. *Garri Potter va maxfiy xona* (one sentence and "…") |
| Over 2,500 characters | 149 | e.g. *Atom odatlar* 3,620 characters |
| One block with no paragraphs (> 900 chars) | 76 | Mostly bot texts |
| Shop or marketing clichés | ~370 | "befarq qoldirmaydi", "captivating… unforgettable journey" (publisher copy, not ours) |
| Price or sales words | ~180 | Includes some false hits |
| Emoji | 110 | Copied from Instagram-style shop posts |
| Spec sheet pasted as text | 33 | "Muallif: … Hajmi: … Format: 60x90 1/16 Muqova: …" |
| Raw HTML or entities | 6 | |

Our own 63 texts: only 2 small flags.

## Where to put effort

Of the top 200 viewed books without a text of our own:

- **149 we can write about** without the book in hand: fiction, popular
  non-fiction, classics. Top ones: *Harvard metodi* (114 views, nothing
  shown), *Shoh va mot* (86), Goldratt's *Maqsad* (63, nothing), *Bilsang edi*,
  *Sofiyaning dunyosi*, *Ranjkom*, *Hayot qadri*, *Oqsoch*, *Atom odatlar*,
  *Odam bo'lish qiyin*.
- **51 need the book or the publisher's own information**: test prep
  (*Matematika Master* 119 views, the *Multilevel* series), textbooks and
  religious titles. Writing these blind would mean guessing. Ask the
  publishers. Several of these are Telegram-only publishers we already
  follow.

Side findings for moderators: the same book seems to exist twice in a few
places (*Qoida №1: Hech qachon №2 bo'lma* and *Qoida No1: hech qachon No2
bo'lma*; *Matematika Master* and *Мастер математик* may be separate
language editions). See `DUPLICATE-BOOKS.md`.

## Recommendations

1. **Write our own texts for the 149 "writable" books first**, in Uzbek, then
   Russian and English written for those readers, not translated word for word.
   Same flow as the pilot: draft → fact-check → owner approves → import.
2. **Keep the Asaxiy text on the page for now, but under ours.** It's credited
   and readers find it useful. Once a book has its own text, show ours first
   and fold the shop's text under "Do'kon tavsifi". Deleting 8,500 texts would
   leave thin pages, which is worse.
3. **Series blurbs:** one short line of our own per book saying what *this*
   volume is (level, story, age) fixes 1,253 duplicates cheaply.
4. **Don't mass-generate texts for the long tail.** 13,000 books get 0–2 views
   a quarter. Generating text for them in bulk is exactly what Google's
   scaled-content policy targets. Grow out from what readers actually open.
5. **The import needs a replace mode with review.** `books:import-descriptions`
   only fills blanks today. Replacing a copied text needs a "replace when the
   current text is a shop copy" option plus the dry-run log.
6. **Measure again in 8–12 weeks:** own-text share, views on rewritten books
   compared with the rest, and Search Console impressions once GSC is set up.
