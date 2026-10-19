<?php

namespace App\Http\Resources\Api;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published quote: the line, its page, who translated it when it comes
 * from a translation, and — away from its book's own page — the book.
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'body' => $this->body,
            'page' => $this->page,
            // [] for a quote with no edition: whenLoaded() would give null there.
            'translators' => $this->when($this->relationLoaded('edition'), fn () => $this->edition?->translators->pluck('name')->values()->all() ?? []),
            'book' => $this->whenLoaded('book', fn () => [
                'id' => (string) $this->book->id,
                'uzbekTitle' => $this->book->uzbek_title,
                'author' => $this->book->author_names,
                'coverUrl' => $this->book->primaryEdition?->cover_url,
            ]),
        ];
    }
}
