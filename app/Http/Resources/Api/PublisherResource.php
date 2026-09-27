<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublisherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'bio' => $this->bio,
            'country' => $this->country,
            'establishedYear' => $this->established_year,
            'logoUrl' => $this->logo_url,
            'websiteUrl' => $this->website_url,
            'bookCount' => $this->when($this->books_count !== null, fn () => (int) $this->books_count),
            // A few covers for the card; see CatalogueCovers.
            'coverUrls' => $this->when($this->cover_urls !== null, fn () => $this->cover_urls),
        ];
    }
}