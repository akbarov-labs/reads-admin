<?php

namespace App\Services\Stores;

use App\Models\Edition;
use App\Models\StoreListing;
use App\Support\UzbekText;
use App\Support\WebpImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compares a store listing with the catalogue edition of the same ISBN.
 *
 * It only ever fills a blank: a cover the edition lacks (downloaded and
 * kept as WebP, never hot-linked) and a missing page count. Everything else
 * the two disagree on (publisher, author, pages) is written on the listing
 * for an admin to look at, and the catalogue is left as it is.
 *
 * Filling a blank clears the edition's sync_hash, so the bot, which had the
 * edition without it, gets it again on its next pull.
 */
class StoreListingApplier
{
    /**
     * @return array{matched: bool, filled: list<string>, differences: array<string, array{0: mixed, 1: mixed}>}
     */
    public function apply(StoreListing $listing, bool $dryRun = false): array
    {
        $edition = $listing->isbn !== null
            ? Edition::with(['book.authors', 'publisher'])->where('isbn', $listing->isbn)->first()
            : null;

        if ($edition === null) {
            if (! $dryRun) {
                $listing->forceFill(['edition_id' => null, 'applied_at' => now(), 'filled' => null, 'differences' => null])->save();
            }

            return ['matched' => false, 'filled' => [], 'differences' => []];
        }

        $filled = [];
        $changes = [];

        if (blank($edition->cover_path) && $listing->image_url !== null) {
            $path = $dryRun ? 'dry-run' : $this->storeCover($listing);

            if ($path !== null) {
                $changes['cover_path'] = $path;
                $filled[] = 'cover';
            }
        }

        if ($edition->pages === null && $listing->pages !== null) {
            $changes['pages'] = $listing->pages;
            $filled[] = 'pages';
        }

        $differences = $this->differences($edition, $listing);

        if (! $dryRun) {
            if ($changes !== []) {
                $edition->forceFill([...$changes, 'sync_hash' => null])->save();
            }

            $listing->forceFill([
                'edition_id' => $edition->id,
                'applied_at' => now(),
                'filled' => $filled !== [] ? $filled : $listing->filled,
                'differences' => $differences !== [] ? $differences : null,
            ])->save();
        }

        return ['matched' => true, 'filled' => $filled, 'differences' => $differences];
    }

    /** @return array<string, array{0: mixed, 1: mixed}> field => [catalogue, store] */
    private function differences(Edition $edition, StoreListing $listing): array
    {
        $differences = [];
        $ours = $edition->publisher?->name;

        if ($ours !== null && $listing->publisher !== null
            && UzbekText::publisherKey($ours) !== UzbekText::publisherKey($listing->publisher)) {
            $differences['publisher'] = [$ours, $listing->publisher];
        }

        $authors = $edition->book?->authors->pluck('name')->all() ?? [];

        if ($listing->author !== null) {
            $theirs = UzbekText::key($listing->author);
            $known = array_map(fn (string $name) => UzbekText::key($name), $authors);

            if (! in_array($theirs, $known, true)) {
                $differences['author'] = [$authors !== [] ? implode(', ', $authors) : null, $listing->author];
            }
        }

        // A few pages either way is a different count, not a different book.
        if ($edition->pages !== null && $listing->pages !== null && abs($edition->pages - $listing->pages) > 8) {
            $differences['pages'] = [$edition->pages, $listing->pages];
        }

        return $differences;
    }

    /** The stored cover's path on the public disk, or null when it couldn't be had. */
    private function storeCover(StoreListing $listing): ?string
    {
        // The image comes from the same shop: the same pause as its pages.
        usleep(max(0, (int) config('services.qamar.delay_ms')) * 1000);

        try {
            $response = Http::withUserAgent(config('services.qamar.user_agent'))
                ->connectTimeout(10)
                ->timeout(30)
                ->get($listing->image_url);
        } catch (Throwable) {
            return null;
        }

        $webp = $response->successful() ? WebpImage::from($response->body()) : null;

        if ($webp === null) {
            return null;
        }

        $path = "covers/{$listing->store}/{$listing->isbn}.webp";

        return Storage::disk('public')->put($path, $webp) ? $path : null;
    }
}
