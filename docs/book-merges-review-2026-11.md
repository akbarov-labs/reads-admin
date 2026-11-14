# Book merges, November 2026: reviewed list

For the owner to approve before anything runs. Built from
`docs/merge-candidates-2026-11.md` and a read of each book on the live
site's public API (14 Nov 2026): editions, description, reader activity.

- **259 merges** into **231 kept books**: `database/data/book-merges-2026-11.csv` (`keep_id,merge_id`, then titles and why).
- **24** rows need a look (the `check` column): mostly one author spelt two ways (the kept book's credit stays, so merge the authors too), and five records whose author field holds the title (not carried over).
- **74** kept titles still carry a format note ("(A5)", "(мягкая)"): rename them after merging. The page shows the kept title for every edition that has no title of its own.
- **18** books or groups left out (below): every doubt the drafter flagged. All 526 books in the list were on the site when checked.

## How the kept book was chosen

In order: its own description (not one copied from an edition), more page
views in the last 90 days (top-200 list only; the rest count as 0), more
editions, a fuller author list, a cleaner title (no "(A5)", "(мягкая)", "Author: " prefix or stray
time), more reader activity (taqrizlar, quotes, reviews, library copies,
bot readers), then the lowest id. The kept book keeps its title, own
description, authors and genre; see docs/DATA-MODEL.md, "Merging books".

## How to run it (after approval)

```sh
php artisan migrate                       # creates book_merges
php artisan books:merge database/data/book-merges-2026-11.csv --dry-run
# read storage/app/private/book-merges/*-dry-run.csv, then:
php artisan books:merge database/data/book-merges-2026-11.csv
php artisan books:unmerge <merge_id> ...  # undo: the merged books' old ids
```

Strike a row from the CSV (or prefix it with `#`) to leave it out.

## Rows with something to check

Format-note-only rows are left out of this table; they are in the CSV.

| keep | merge | kept title | check |
|---:|---:|---|---|
| 4398 | 5249 | Persuasion | authors differ: Jane Austen / Djeyn Ostin (kept stays; merge the authors if one person) |
| 4398 | 5767 | Persuasion | authors differ: Jane Austen / Djeyn Ostin (kept stays; merge the authors if one person) |
| 1482 | 10965 | Fotima roziyallohu anhoning isrofsiz to'yi | kept has no author, takes Raxmonov M. |
| 4973 | 8394 | Психология влияния и обмана инструкция для манипуляторов | authors differ: Светлана Кузина Валерьевна / Кузина Светлана (kept stays; merge the authors if one person) |
| 2171 | 4627 | F.Scott Fitzgerald: The Great Gatsby | authors differ: Francis Scott Fitzgerald / F.Scott Fitzgerald (kept stays; merge the authors if one person) |
| 4264 | 5260 | Jane Eyre | authors differ: Charlotte Bronte / Sharlotta Bronte (kept stays; merge the authors if one person) |
| 8763 | 13335 | Nick Hornby - How To Be Good (used) | its author field holds the title: not carried over  |
| 2307 | 3979 | Jane Austen: Pride and Prejudice | authors differ: Djeyn Ostin / Jane Austen (kept stays; merge the authors if one person) |
| 2307 | 6908 | Jane Austen: Pride and Prejudice | authors differ: Djeyn Ostin / Jane Austen (kept stays; merge the authors if one person) |
| 2307 | 7961 | Jane Austen: Pride and Prejudice | authors differ: Djeyn Ostin / Jane Austen (kept stays; merge the authors if one person) |
| 4235 | 1135 | Клиенты на всю жизнь (А6) | authors differ: Karl Syuell, Pol Braun / Karl Syuell (kept stays; merge the authors if one person)  |
| 3360 | 2630 | Все закончится на нас | authors differ: Колин Гувер / Colleen Hoover (kept stays; merge the authors if one person) |
| 6366 | 3031 | Good omens (Penguin) | authors differ: Neil Gaiman, Terry Pratchett / Neil Gaiman (kept stays; merge the authors if one person)  |
| 5082 | 477 | 21 урок для XXI века (оригинал) | authors differ: Юваль Ной Харари / Yuval Noah Harari (kept stays; merge the authors if one person)  |
| 8286 | 6996 | Куриный бульон для души. Все будет хорошо! 101 история со счастливым концом (мягкая) | authors differ: Джек Кэнфилд, Марк Виктор Хансен, Эми Ньюмарк / Эми Ньюмарк (kept stays; merge the authors if one person)  |
| 3015 | 4976 | Фрэнсис Скотт Фицджеральд: Великий Гэтсби | authors differ: Frensis Skot Fijerald / Francis Scott Fitzgerald (kept stays; merge the authors if one person) |
| 2610 | 9329 | Ey ko'ngil pushaymonmisan? | authors differ: Xadicha Kubro Tongar / Xadicha Kubro Ipak (kept stays; merge the authors if one person) |
| 372 | 3404 | Гордость и предубеждение | authors differ: Jeyn Osten / Djeyn Ostin (kept stays; merge the authors if one person) |
| 372 | 5487 | Гордость и предубеждение | authors differ: Jeyn Osten / Djeyn Ostin (kept stays; merge the authors if one person) |
| 2915 | 3565 | Луиза Мэй Олкотт: Маленькие женщины | authors differ: Luiza Olkott / Luiza Mey Olkott (kept stays; merge the authors if one person) |
| 4326 | 8930 | Космос (Детская энциклопедия) | its author field holds the title: not carried over  |
| 2767 | 8912 | Huzur Ajandası - Sabir | its author field holds the title: not carried over |
| 7434 | 8944 | Обучающие карточки 16л. Питомцы | its author field holds the title: not carried over |
| 7935 | 9006 | Обучающие карточки 16л. АЛФАВИТ | its author field holds the title: not carried over |

## Left out

| books | titles | why |
|---|---|---|
| 6803 | Qur'oni Karim. Tajvidli Mus'haf. 30 juz | a mushaf from another publisher (Sano-standart): the note merges only 3056 and 4061 (rest of its group merges) |
| 446, 4073 | Джордж Оруэлл: 1984 (А6) (Новый перевод) / Джордж Оруэлл: 1984 (А5) (Новый перевод) | publishers differ in the records (AST / Alpina): worth a look |
| 459, 7707 | Idealist o'qituvchi / Ideal muallim | two Uzbek titles; the note asks to confirm (may be two translations) |
| 505, 12835 | Ihyou Ulumid din / Ihyou ulumid din Abu Homid G'azzoliy | multi-volume work, publishers differ: check it is the same volume |
| 5274, 7597 | Barron's IELTS Practice Exams. The Leader in Test Preparation (soft) / IELTS Practice Exams (Barron's Ielts Practice Exams) (soft) | page counts differ (323 / 460): may be two editions |
| 2614, 6046 | Collins English for Exams. Listening for IELTS / Collins English for Exams. Listening for IELTS (A5) | page counts differ (160 / 144): may be first and second edition |
| 8180, 8349 | Geografiya (variant testlari) / Geografiya (variant testlari) | authors differ (Shodmonov / Oxunjonova): may be two books |
| 148, 431, 2146 | Vavilonlik eng boy odam / Vavilondagi eng boy odam / Jorj Samuel Kleyson: Vavilonlik eng boy odam | possibly different translations under two titles |
| 440, 5176 | К себе нежно (твёрдый переплёт) / К себе нежно + блокнот (Комплект) | set with a notebook: may stay a separate product |
| 257, 663, 1127 | Oydin tunlar (Oltin qalam) / Oydin tunlar / Oydin tunlar (Tirilish) | page counts 110-192: some editions may add other stories |
| 3693, 4658, 5578 | Капитал (pocketbook) / Капитал в одном томе. Полная версия / Капитал (A6) | three different abridgements (384 / 512 / 960 pages): not one text |
| 4917 | Капитанская дочка. Всемирная литература | at 384 pages it probably holds other works too (rest of its group merges) |
| 2665 | O'zbekiston Respublikasi Konstitutsiyasi. 2025 A6 | the 2025 printing may carry later amendments (rest of its group merges) |
| 4993, 8316 | Грокаем алгоритмы. Иллюстрированное пособие для программистов и любопытствующих (твердая) / Грокаем алгоритмы. Иллюстрированное пособие для программистов и любопытствующих (мякгая) (оригинал) | may be the second edition (288 / 352 pages) |
| 1461, 2627 | Harry Potter. The complete collection (Books 1-7) / Harry Potter. The complete series. Special edition (Books 1-7) | box sets: may stay separate products |
| 418, 5917 | Психология влияния (7-е расширенное издание) / Психология влияния. Убеждай, воздействуй, защищайся | older and newer (7th, expanded) edition: same question as «Грокаем алгоритмы» |
| 3953, 6058 | Язык телодвижений. Как читать мысли окружающих по их жестам (мягкая) / Аллан и Барбара Пиз: Новый язык телодвижений (твёрдый) | the note cannot tell the 1978 book from the new edition |
| 3241, 3016 | Lewis Carroll: Alice's Adventures in Wonderland / Alice's Adventures Wonderland | only 96 pages: check whether this edition is abridged |
