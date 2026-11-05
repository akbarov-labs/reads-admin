<?php

namespace App\Services;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Edition;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue book behind an announcement, made when it is published: the
 * book itself, its authors, and an edition for the announcing publisher with
 * the ISBN, pages and year an admin typed. A book already in the catalogue
 * (the same ISBN, or the same title by the same author under any spelling)
 * is linked instead of made twice.
 */
class AnnouncementBook
{
    public function __construct(private readonly CatalogueRecords $records) {}

    public function ensure(Announcement $announcement): Book
    {
        return DB::transaction(function () use ($announcement) {
            $isbn = $announcement->isbn !== null ? CatalogueSync::normalizeIsbn($announcement->isbn) : null;

            $book = ($isbn !== null ? Edition::query()->where('isbn', $isbn)->first()?->book : null)
                ?? $this->records->findBook($announcement->author_name ?: null, $announcement->title);

            if ($book === null) {
                $book = Book::create([
                    'uzbek_title' => $announcement->title,
                    'description' => $announcement->description,
                    'status' => ModerationStatus::Approved,
                ]);

                if ($announcement->author_name) {
                    $book->authors()->attach($this->records->author($announcement->author_name)->id, ['position' => 0]);
                }
            }

            $this->ensureEdition($book, $announcement, $isbn);

            return $book;
        });
    }

    private function ensureEdition(Book $book, Announcement $announcement, ?string $isbn): void
    {
        $edition = $book->editions()->where('publisher_id', $announcement->publisher_id)->first();

        if ($edition === null && $isbn !== null && Edition::query()->where('isbn', $isbn)->exists()) {
            return;
        }

        if ($edition === null) {
            $edition = $book->editions()->create([
                'publisher_id' => $announcement->publisher_id,
                'isbn' => $isbn,
                'year' => Edition::plausibleYear($announcement->year),
                'pages' => $announcement->pages,
                'description' => $announcement->description,
                'cover_path' => $announcement->cover_path,
                'language' => 'uz',
                'status' => ModerationStatus::Approved,
            ]);

            if ($announcement->translator_name) {
                $edition->translators()->attach($this->records->translator($announcement->translator_name)->id, [
                    'role' => BookRole::Translator->value,
                    'position' => 0,
                ]);
            }

            return;
        }

        // The publisher's edition is already here: fill what it lacks, never overwrite.
        $edition->fill(array_filter([
            'isbn' => $edition->isbn ?? $isbn,
            'year' => $edition->year ?? Edition::plausibleYear($announcement->year),
            'pages' => $edition->pages ?? $announcement->pages,
            'cover_path' => $edition->cover_path ?? $announcement->cover_path,
        ], fn ($value) => $value !== null))->save();
    }
}
