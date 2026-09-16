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
            'bookCount' => $this->whenCounted('books'),
        ];
    }
}