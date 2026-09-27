<?php

namespace App\Http\Resources\Api;

use App\Models\Book;
use App\Models\Edition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A book as a catalogue card: the work, plus the fields a card shows —
 * cover, publisher, year, translator — taken from its primary edition
 * (Book::getPrimaryEditionAttribute). Those flat fields are the shape the
 * site has always read (Reads/lib/types.ts `Book`), so a card still renders
 * from one row; `editionCount` says whether there is more to see.
 *
 * @mixin Book
 */
class BookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Edition|null $edition */
        $edition = $this->primary_edition;
        $authors = $this->authors;
        $firstAuthor = $authors->first();
        $credits = $edition?->translators ?? collect();
        $translator = $credits->first();

        return [
            'id' => (string) $this->id,
            'uzbekTitle' => $this->uzbek_title,
            'originalTitle' => $this->original_title,
            'sourceLanguage' => $this->source_language,

            'author' => (string) $this->author_names,
            'authorId' => $firstAuthor ? (string) $firstAuthor->id : null,
            'authorImageUrl' => $firstAuthor?->portrait_url,
            'authors' => $authors->map(fn ($author) => [
                'id' => (string) $author->id,
                'slug' => $author->slug,
                'name' => $author->name,
            ])->values(),

            'publisher' => (string) $edition?->publisher?->name,
            'publisherId' => $edition?->publisher_id ? (string) $edition->publisher_id : null,
            'publisherSlug' => $edition?->publisher?->slug,
            'publisherName' => $edition?->publisher?->name,
            'publisherImageUrl' => $edition?->publisher?->logo_url,
            'year' => $edition?->year,
            'coverUrl' => $edition?->cover_url,

            // The first credit on the primary edition, as before; every
            // credit is in `translators`.
            'role' => $translator?->pivot->role?->value,
            'translator' => $translator ? ['slug' => $translator->slug, 'name' => $translator->name] : null,
            'translators' => TranslatorCreditResource::collection($credits),

            'excerpt' => $edition?->hasExcerpt() ? EditionResource::excerpt($edition) : null,

            'categories' => $this->categories->map(fn ($category) => [
                'slug' => $category->slug,
                'name' => $category->name,
                'isPrimary' => (bool) $category->pivot->is_primary,
            ])->values(),
            'series' => $this->series ? [
                'slug' => $this->series->slug,
                'name' => $this->series->name,
                'position' => $this->series_position,
            ] : null,
            'editionCount' => $this->approvedEditions->count(),
        ];
    }

    /**
     * What every endpoint returning book cards eager-loads, so none of them
     * trips N+1 on the fields above.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'authors',
            'categories',
            'series',
            'approvedEditions' => fn ($query) => $query->with(['publisher', 'translators']),
        ];
    }
}
