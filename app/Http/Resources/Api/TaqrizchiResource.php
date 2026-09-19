<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reviewer's public profile. Deliberately the same shape as
 * TranslatorResource where the two overlap, so the site can render both with
 * the same profile components.
 */
class TaqrizchiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'title' => $this->title ?: null,
            'avatarUrl' => $this->avatar_url,
            'location' => $this->location,
            'bio' => $this->bio,
            'socialLinks' => $this->socialLinks->map(fn ($link) => [
                'platform' => $link->platform->value,
                'url' => $link->url,
            ]),
            // Approved only — see the controller's eager load. A pending
            // review must not show up anywhere public, including as a count.
            'totalTaqrizlar' => $this->taqrizlar->count(),
            'taqrizlar' => TaqrizResource::collection($this->taqrizlar),
            'updatedAt' => $this->content_updated_at?->toAtomString(),
        ];
    }
}
