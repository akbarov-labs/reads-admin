<?php

namespace App\Console\Commands;

use App\Models\StoreListing;
use App\Services\SiteRevalidator;
use App\Services\Stores\QamarFeed;
use App\Services\Stores\StoreListingApplier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('stores:qamar
    {--limit= : Read at most this many pages (default: services.qamar.pages_per_run)}
    {--dry-run : Read and compare, but change no edition}
    {--no-read : Only compare the listings already saved}')]
#[Description('Read qamar.uz book pages that are new or changed, and fill catalogue blanks (cover, pages) by ISBN')]
class ReadQamar extends Command
{
    public function handle(QamarFeed $feed, StoreListingApplier $applier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->option('no-read')) {
            try {
                $sitemap = $feed->sitemap();
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
            $read = $feed->read($sitemap, $limit);

            $this->line(sprintf(
                'Sitemap: %d books; %d new or changed; read %d (%d with book details, %d re-read for price and stock).',
                count($sitemap), $read['due'], $read['read'], $read['books'], $read['refreshed'],
            ));

            if ($read['stopped'] !== null) {
                $this->warn("Stopped early: {$read['stopped']}");
            }
        }

        // Every listing not compared since it was last read, and every one
        // still without an edition: its book may have been added since.
        $listings = StoreListing::where('store', QamarFeed::STORE)
            ->whereNotNull('isbn')
            ->where(fn ($query) => $query
                ->whereNull('applied_at')
                ->orWhereColumn('fetched_at', '>', 'applied_at')
                ->orWhereNull('edition_id'))
            ->orderBy('id')
            ->get();

        $counts = ['compared' => 0, 'matched' => 0, 'new to us' => 0, 'cover' => 0, 'pages' => 0, 'with differences' => 0];
        $log = ['edition_id,isbn,field,value'];

        foreach ($listings as $listing) {
            $result = $applier->apply($listing, $dryRun);
            $counts['compared']++;
            $counts[$result['matched'] ? 'matched' : 'new to us']++;
            $counts['with differences'] += $result['differences'] !== [] ? 1 : 0;

            foreach ($result['filled'] as $field) {
                $counts[$field]++;
                $value = $field === 'cover' ? "covers/qamar/{$listing->isbn}.webp" : (string) $listing->pages;
                $log[] = ($listing->edition_id ?? '').",{$listing->isbn},{$field},\"{$value}\"";
            }
        }

        $this->table(['', 'Listings'], collect($counts)->map(fn ($count, $what) => [$what, $count])->values()->all());

        $restocked = ! $this->option('no-read') && ($read['read'] ?? 0) > 0;

        if (! $dryRun && count($log) > 1) {
            // To undo: clear these fields on these editions.
            $path = 'store-feeds/qamar-'.now()->format('Y-m-d_His').'.csv';
            Storage::disk('local')->put($path, implode("\n", $log)."\n");
            $this->line('Logged to '.Storage::disk('local')->path($path));

            // Covers show on book pages, listings and author and publisher cards.
            app(SiteRevalidator::class)->everything();
        } elseif (! $dryRun && $restocked) {
            // Prices and "in stock" show on book pages.
            app(SiteRevalidator::class)->everything();
        }

        $this->info(sprintf(
            '%s %d covers and %d page counts.',
            $dryRun ? 'Would fill' : 'Filled',
            $counts['cover'],
            $counts['pages'],
        ));

        return self::SUCCESS;
    }
}
