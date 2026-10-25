<?php

namespace App\Http\Resources\Api;

use App\Models\Library;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A library in the list (where it is, how many books) or on its own page,
 * with how to reach it. `stale` is true once its list has gone unconfirmed
 * for Library::STALE_AFTER_DAYS, and the site says so next to "bor".
 *
 * @mixin Library
 */
class LibraryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'region' => $this->region,
            'district' => $this->district,
            'address' => $this->address,
            'photoUrl' => $this->photo_url,
            'bookCount' => $this->when(isset($this->books_count), fn () => (int) $this->books_count),
            'holdingsUpdatedAt' => $this->holdings_updated_at?->toIso8601String(),
            'stale' => self::isStale($this->resource),
            $this->mergeWhen($request->route('library') !== null, fn () => [
                'description' => $this->description,
                'hours' => $this->hours,
                'membership' => $this->membership,
                'phone' => $this->phone,
                'telegramUrl' => $this->telegram_url,
                'instagramUrl' => $this->instagram_url,
                'websiteUrl' => $this->website_url,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ]),
        ];
    }

    public static function isStale(Library $library): bool
    {
        return $library->holdings_updated_at === null
            || $library->holdings_updated_at->lt(now()->subDays(Library::STALE_AFTER_DAYS));
    }
}
