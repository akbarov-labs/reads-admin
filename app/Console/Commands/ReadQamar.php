<?php

namespace App\Console\Commands;

use App\Models\Edition;
use App\Models\StoreListing;
use App\Services\SiteRevalidator;
use App\Services\Stores\QamarFeed;
use App\Services\Stores\StoreListingApplier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('stores:qamar
    {--limit= : Read at most this many pages (default: services.qamar.pages_per_run)}
    {--dry-run : Read and compare, but change no edition}
    {--no-read : Only compare the listings already saved}
    {--covers= : Download at most this many missing covers (default: services.qamar.covers_per_run)}')]
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

        // First, every listing whose ISBN is a catalogue edition's is linked
        // to it in one update: no page or picture to fetch, so nothing can
        // stop it, and its price and stock show on the book page tonight.
        $linked = $dryRun ? 0 : self::linkByIsbn();

        // Every listing not compared since it was last read, and every one
        // still without an edition: its book may have been added since.
        // Never-compared ones first, so a short run still reaches them.
        $listings = StoreListing::where('store', QamarFeed::STORE)
            ->whereNotNull('isbn')
            ->where(fn ($query) => $query
                ->whereNull('applied_at')
                ->orWhereColumn('fetched_at', '>', 'applied_at')
                ->orWhereNull('edition_id'))
            ->orderByRaw('applied_at is not null')
            ->orderBy('id')
            ->get();

        $counts = ['linked by ISBN' => $linked, 'compared' => 0, 'matched' => 0, 'new to us' => 0, 'cover' => 0, 'pages' => 0, 'with differences' => 0, 'errors' => 0];
        $log = ['edition_id,isbn,field,value'];

        // Covers are fetched from the shop one by one, with its pause
        // between: a cap keeps a night's run short and the shop's load low.
        // The rest wait for the next night.
        $coversLeft = $this->option('covers') !== null ? max(0, (int) $this->option('covers')) : (int) config('services.qamar.covers_per_run');

        foreach ($listings as $listing) {
            // One listing that fails (a broken picture, a dropped
            // connection) is reported and skipped, never the end of the run.
            try {
                $result = $applier->apply($listing, $dryRun, withCover: $coversLeft > 0);
            } catch (Throwable $e) {
                report($e);
                $counts['errors']++;

                continue;
            }

            if (in_array('cover', $result['filled'], true)) {
                $coversLeft--;
            }

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

        $restocked = (! $this->option('no-read') && ($read['read'] ?? 0) > 0) || $linked > 0;

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

    /**
     * Links every Qamar listing still without an edition to the catalogue
     * edition of the same ISBN, in one statement.
     *
     * @return int The listings linked.
     */
    private static function linkByIsbn(): int
    {
        return DB::table('store_listings')
            ->where('store', QamarFeed::STORE)
            ->whereNull('edition_id')
            ->whereNotNull('isbn')
            ->whereIn('isbn', Edition::query()->select('isbn')->whereNotNull('isbn'))
            ->update(['edition_id' => DB::raw('(select editions.id from editions where editions.isbn = store_listings.isbn limit 1)')]);
    }
}
