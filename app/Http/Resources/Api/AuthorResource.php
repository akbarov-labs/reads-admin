<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'bio' => $this->bio,
            'nationality' => $this->nationality,
            'birthYear' => $this->birth_year,
            'deathYear' => $this->death_year,
            'portraitUrl' => $this->portrait_url,
            'websiteUrl' => $this->website_url,
            'originalName' => $this->original_name,
            'bookCount' => $this->whenCounted('books'),
            // A few covers for the card; see CatalogueCovers.
            'coverUrls' => $this->when($this->cover_urls !== null, fn () => $this->cover_urls),
        ];
    }
}