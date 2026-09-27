<?php

namespace App\Http\Resources\Api;

use App\Models\Taqriz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One review. Scores are emitted only for the aspects the reviewer actually
 * filled in — an absent key means "not rated", which is different from a
 * score of zero and must stay distinguishable on the site.
 */
class TaqrizResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $scores = [];

        foreach ($this->resource->scoredAspects() as $column => $value) {
            $scores[Taqriz::aspectKey($column)] = $value;
        }

        return [
            'id' => (string) $this->id,
            'body' => $this->body,
            'youtubeUrl' => $this->youtube_url,
            'scores' => (object) $scores,
            'averageScore' => $this->resource->average_score,
            'createdAt' => $this->created_at?->toAtomString(),
            'updatedAt' => $this->updated_at?->toAtomString(),
            'taqrizchi' => $this->whenLoaded('taqrizchi', fn () => [
                'slug' => $this->taqrizchi->slug,
                'name' => $this->taqrizchi->name,
                'avatarUrl' => $this->taqrizchi->avatar_url,
            ]),
            // The edition the reviewer read, and the book it is an edition
            // of. `book.coverUrl` is that edition's cover — the one they
            // actually held.
            'book' => $this->whenLoaded('edition', fn () => [
                'id' => (string) $this->edition->book->id,
                'uzbekTitle' => $this->edition->book->uzbek_title,
                'author' => (string) $this->edition->book->author_names,
                'coverUrl' => $this->edition->cover_url,
            ]),
            'edition' => $this->whenLoaded('edition', fn () => [
                'id' => (string) $this->edition->id,
                'title' => $this->edition->title,
                'publisher' => $this->edition->publisher?->name,
                'year' => $this->edition->year,
                'translators' => $this->edition->translators->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name])->values(),
            ]),
        ];
    }
}
