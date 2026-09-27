<?php

namespace App\Http\Resources\Api;

use App\Models\Edition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a translator's portfolio: an edition they worked on, in the
 * `Book` shape the profile page, ExcerptReader and the JSON-LD have always
 * read. `id` is the book's id so the card links to the book's page; the
 * edition fields (publisher, year, cover, excerpt) are this translation's
 * own, and `role` is what this translator did on it.
 *
 * Expects the edition loaded through Translator::editions(), so `pivot` is
 * this translator's credit.
 *
 * @mixin Edition
 */
class TranslatorBookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $book = $this->book;
        $firstAuthor = $book->authors->first();

        return [
            'id' => (string) $book->id,
            'editionId' => (string) $this->id,
            'role' => $this->pivot?->role?->value,
            'uzbekTitle' => $this->display_title,
            'originalTitle' => $book->original_title,
            'author' => (string) $book->author_names,
            'authorId' => $firstAuthor ? (string) $firstAuthor->id : null,
            'authorImageUrl' => $firstAuthor?->portrait_url,
            'sourceLanguage' => $book->source_language,
            'publisher' => (string) $this->publisher?->name,
            'publisherId' => $this->publisher_id ? (string) $this->publisher_id : null,
            'publisherSlug' => $this->publisher?->slug,
            'publisherName' => $this->publisher?->name,
            'publisherImageUrl' => $this->publisher?->logo_url,
            'year' => $this->year,
            'coverUrl' => $this->cover_url,
            'excerpt' => $this->hasExcerpt() ? EditionResource::excerpt($this->resource) : null,
        ];
    }
}
