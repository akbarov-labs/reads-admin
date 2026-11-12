<?php

namespace App\Http\Resources\Api;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One deal as the site shows it: what it is, who runs it, until when, and
 * where a reader takes it up (`url`: the shop's page, or else the post).
 *
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'details' => $this->details,
            'discountPercent' => $this->discount_percent,
            'promoCode' => $this->promo_code,
            'startsOn' => $this->starts_on?->toDateString(),
            'endsOn' => $this->ends_on?->toDateString(),
            'url' => $this->url ?? $this->source_url,
            'sourceUrl' => $this->source_url,
            'imageUrl' => $this->imageUrl(),
            'postedAt' => ($this->posted_at ?? $this->created_at)?->toIso8601String(),
            'source' => [
                'name' => $this->sourceName(),
                'publisherSlug' => $this->publisher?->slug,
            ],
            'book' => $this->book ? ['id' => (string) $this->book->id, 'title' => $this->book->uzbek_title] : null,
        ];
    }
}
