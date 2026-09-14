<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

class CatalogBookResource extends BookResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'translator' => $this->whenLoaded('translator', fn () => [
                'slug' => $this->translator->slug,
                'name' => $this->translator->name,
            ]),
        ]);
    }
}