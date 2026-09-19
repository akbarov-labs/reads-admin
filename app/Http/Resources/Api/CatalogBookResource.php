<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

class CatalogBookResource extends BookResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            // Null for a book nobody has translated. whenLoaded() collapses a
            // loaded-but-null relation to null for us.
            'translator' => $this->whenLoaded('translator', fn () => [
                'slug' => $this->translator->slug,
                'name' => $this->translator->name,
            ]),

            // Approved reviews only — the controller's eager load constrains
            // this, so pending rows never reach the resource.
            'taqrizlar' => TaqrizResource::collection($this->whenLoaded('taqrizlar')),

            // The book's headline score: the mean of each review's own
            // average. Averaging the reviews rather than pooling every raw
            // aspect keeps one thorough reviewer from outweighing three
            // brief ones simply for having filled in more fields.
            'averageScore' => $this->when(
                $this->relationLoaded('taqrizlar'),
                fn () => $this->averageOfTaqrizlar(),
            ),
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
