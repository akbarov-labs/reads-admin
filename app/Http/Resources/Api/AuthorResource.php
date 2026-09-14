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
            'bookCount' => $this->whenCounted('books'),
        ];
    }
}