<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Book` / `Excerpt` shape in the Next.js app's lib/types.ts.
 */
class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => (string) $this->id,
            'role'             => $this->role?->value,
            'uzbekTitle'       => $this->uzbek_title,
            'originalTitle'    => $this->original_title,

            // The catalogue row wins over the free text when a book has been
            // linked to one. The string stays in the column untouched — it is
            // what the row was imported with, and a book that silently
            // reverted to it is how a renamed author comes back from the dead.
            'author'           => $this->authorRecord?->name ?? $this->author,
            'authorId'         => $this->author_id ? (string) $this->author_id : null,
            'authorImageUrl'   => $this->author_image_url,
            'sourceLanguage'   => $this->source_language,

            // Publishers are the other way round: `publisher` stays the raw
            // column because BookCard reads it directly, and the linked name
            // is offered alongside it rather than in its place.
            'publisher'        => $this->publisher,
            'publisherId'      => $this->publisher_id ? (string) $this->publisher_id : null,
            'publisherName'    => $this->publisherRecord?->name,
            'publisherImageUrl'=> $this->publisher_image_url,
            'year'             => $this->year,
            'coverUrl'         => $this->cover_url,
            'excerpt'          => $this->hasExcerpt() ? [
                'sourceLanguage'  => $this->excerpt_source_language,
                'sourceText'      => $this->excerpt_source_text,
                'translatedText'  => $this->excerpt_translated_text,
            ] : null,
        ];
    }
}
