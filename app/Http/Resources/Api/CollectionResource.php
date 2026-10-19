<?php

namespace App\Http\Resources\Api;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A collection in the list (title, description, how many books, a few
 * covers) or on its own page, with its books in order (`items`), each with
 * the edition it means and the editor's line on it.
 *
 * @mixin Collection
 */
class CollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'bookCount' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'coverUrls' => $this->when(isset($this->cover_urls), fn () => $this->cover_urls),
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn ($item) => $item->book !== null)
                ->map(fn ($item) => [
                    'note' => $item->note,
                    'book' => new BookResource($item->book),
                    'edition' => $item->edition ? [
                        'id' => (string) $item->edition->id,
                        'publisher' => $item->edition->publisher?->name,
                        'year' => $item->edition->year,
                        'translators' => $item->edition->translators->pluck('name')->values()->all(),
                    ] : null,
                ])
                ->values()
                ->all()),
        ];
    }
}
