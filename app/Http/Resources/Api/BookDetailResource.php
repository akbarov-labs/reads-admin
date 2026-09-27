<?php

namespace App\Http\Resources\Api;

use App\Models\Book;
use Illuminate\Http\Request;

/**
 * A book's own page: the card fields, plus every approved edition, the
 * approved taqrizlar of all of them, and what to read next.
 *
 * @mixin Book
 */
class BookDetailResource extends BookResource
{
    /** @param  iterable<Book>  $related */
    public function __construct(Book $book, private readonly iterable $related = [])
    {
        parent::__construct($book);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'editions' => EditionResource::collection($this->approvedEditions),

            // Approved only — the controller constrains the eager load, so
            // pending rows never reach the resource.
            'taqrizlar' => TaqrizResource::collection($this->whenLoaded('taqrizlar')),

            // The book's headline score: the mean of each review's own
            // average. Averaging the reviews rather than pooling every raw
            // aspect keeps one thorough reviewer from outweighing three
            // brief ones simply for having filled in more fields.
            'averageScore' => $this->when($this->relationLoaded('taqrizlar'), fn () => $this->averageOfTaqrizlar()),

            'relatedBooks' => BookResource::collection(collect($this->related)),
        ]);
    }

    private function averageOfTaqrizlar(): ?float
    {
        $averages = $this->taqrizlar
            ->map(fn ($taqriz) => $taqriz->average_score)
            ->filter(fn ($score) => $score !== null);

        return $averages->isEmpty() ? null : round($averages->avg(), 1);
    }
}
