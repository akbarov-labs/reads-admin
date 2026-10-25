<?php

namespace App\Http\Resources\Api;

use App\Models\LibraryHolding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A book on a library's page: the usual book card and the library's copies.
 *
 * @mixin LibraryHolding
 */
class LibraryHoldingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'book' => new BookResource($this->book),
            'copiesTotal' => $this->copies_total,
            'copiesAvailable' => $this->copies_available,
        ];
    }
}
