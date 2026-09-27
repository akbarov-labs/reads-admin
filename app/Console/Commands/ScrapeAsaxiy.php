<?php

namespace App\Console\Commands;

use App\Support\Asaxiy\AsaxiyCatalog;
use App\Support\Asaxiy\AsaxiyPage;
use GdImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('asaxiy:scrape
    {--file= : The Asaxiy workbook. Defaults to storage/app/public/asaxiy_books.xlsx}
    {--dir= : Where images go. Defaults to storage/app/public/downloads}
    {--cache= : Where parsed pages go. Defaults to storage/app/asaxiy/pages}
    {--gallery : Also download each edition\'s gallery photos (about 9 per book)}
    {--concurrency=2 : Requests in flight at once}
    {--delay=1500 : Milliseconds to wait between batches}
    {--limit= : Stop after this many products, for a trial run}
    {--quality=85 : WebP quality for images that arrive as JPEG/PNG}')]
#[Description('Fetch every Asaxiy book page once, cache what it says, and download its images as WebP')]
class ScrapeAsaxiy extends Command
{
    /** Consecutive all-failed batches after which the site is assumed to be blocking us. */
    private const GIVE_UP_AFTER = 6;

    private const IMAGES_AT_ONCE = 4;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    private string $dir;

    private string $cache;

    /** @var resource */
    private $failures;

    private int $failed = 0;

    public function handle(): int
    {
        $file = $this->option('file') ?: storage_path('app/public/asaxiy_books.xlsx');
        $this->dir = rtrim($this->option('dir') ?: storage_path('app/public/downloads'), '/');
        $this->cache = rtrim($this->option('cache') ?: storage_path('app/asaxiy/pages'), '/');

        if (! is_file($file)) {
            $this->error("Workbook not found: {$file}");

            return self::FAILURE;
        }

        foreach ([$this->dir, $this->cache] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
                $this->error("Cannot create {$dir}");

                return self::FAILURE;
            }
        }

        $products = $this->pendingProducts($file);

        if ($this->option('limit') !== null) {
            $products = array_slice($products, 0, (int) $this->option('limit'), true);
        }

        $this->info(count($products).' products with something left to fetch.');

        // Rewritten each run: anything still missing is retried anyway, so
        // the file only ever describes what the latest run could not get.
        $this->failures = fopen("{$this->dir}/_failures.csv", 'w');
        $bar = $this->output->createProgressBar(count($products));
        $blockedStreak = 0;

        foreach (array_chunk($products, max(1, (int) $this->option('concurrency')), true) as $chunk) {
            $failedBefore = $this->failed;

            $this->fetchPages($chunk);
            $this->fetchImages($chunk);

            $bar->advance(count($chunk));

            // A whole batch failing means rate limiting, not bad pages. Back
            // off harder each time, and stop rather than record thousands of
            // bogus failures.
            if ($this->failed - $failedBefore >= count($chunk)) {
                if (++$blockedStreak >= self::GIVE_UP_AFTER) {
                    $bar->finish();
                    fclose($this->failures);
                    $this->newLine(2);
                    $this->error('asaxiy.uz stopped answering. Wait a while and run the command again to continue where it stopped.');

                    return self::FAILURE;
                }

                sleep(min(600, 30 * 2 ** ($blockedStreak - 1)));
            } else {
                $blockedStreak = 0;
            }

            usleep((int) $this->option('delay') * 1000);
        }

        $bar->finish();
        fclose($this->failures);
        $this->newLine(2);
        $this->info('Done.');

        if ($this->failed > 0) {
            $this->warn("{$this->failed} requests failed; see {$this->dir}/_failures.csv. Run the command again to retry them.");
        }

        return self::SUCCESS;
    }

    /**
     * Every product whose page is not cached yet, or whose images are not
     * all on disk. Keyed by Asaxiy's article number, one per listing.
     *
     * @return array<string, array{url: string, key: string}>
     */
    private function pendingProducts(string $file): array
    {
        $products = [];

        foreach ((new AsaxiyCatalog($file))->rawRows() as $raw) {
            $article = $raw['article'] ?? null;

            if ($article === null || empty($raw['url'])) {
                continue;
            }

            $product = ['url' => $raw['url'], 'key' => AsaxiyCatalog::imageKey($raw)];
            $page = $this->cached($article);

            if ($page === null || ! $this->imagesDone($product['key'], $page)) {
                $products[$article] = $product;
            }
        }

        return $products;
    }

    /** @param  array<string, array{url: string, key: string}>  $chunk */
    private function fetchPages(array $chunk): void
    {
        $missing = array_filter($chunk, fn ($product, $article) => $this->cached((string) $article) === null, ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return;
        }

        $responses = Http::pool(fn (Pool $pool) => collect($missing)
            ->map(fn (array $product, string $article) => $this->request($pool->as((string) $article))->get($product['url']))
            ->all());

        foreach ($missing as $article => $product) {
            $response = $responses[(string) $article] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                $this->recordFailure((string) $article, $product['url'], $response, 'page');

                continue;
            }

            $page = ['url' => $product['url'], 'fetched_at' => now()->toAtomString(), ...AsaxiyPage::parse($response->body())];
            $this->writeAtomically("{$this->cache}/{$article}.json", json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /** @param  array<string, array{url: string, key: string}>  $chunk */
    private function fetchImages(array $chunk): void
    {
        $wanted = [];

        foreach ($chunk as $article => $product) {
            $page = $this->cached((string) $article);

            foreach ($page ? $this->imagesFor($product['key'], $page) : [] as $target => $url) {
                if (! is_file($target)) {
                    $wanted[$target] = $url;
                }
            }
        }

        if ($wanted === []) {
            return;
        }

        // A product with a gallery wants ten images; a few at a time keeps
        // the CDN from seeing a burst.
        foreach (array_chunk($wanted, self::IMAGES_AT_ONCE, true) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => collect($batch)
                ->map(fn (string $url, string $target) => $this->request($pool->as($target))->get($url))
                ->all());

            foreach ($batch as $target => $url) {
                $error = $this->storeWebp($target, $responses[$target] ?? null);

                if ($error !== null) {
                    $this->failed++;
                    fputcsv($this->failures, [basename($target), $url, $error]);
                }
            }
        }
    }

    /**
     * Local path => remote URL for every image this product should have:
     * the cover as <key>.webp, and with --gallery its photos as
     * <key>-2.webp, <key>-3.webp … (the cover being number 1).
     *
     * @param  array<string, mixed>  $page
     * @return array<string, string>
     */
    private function imagesFor(string $key, array $page): array
    {
        $images = [];

        if ($page['cover'] ?? null) {
            $images["{$this->dir}/{$key}.webp"] = $page['cover'];
        }

        if ($this->option('gallery')) {
            foreach (array_values($page['gallery'] ?? []) as $i => $url) {
                $images["{$this->dir}/{$key}-".($i + 2).'.webp'] = $url;
            }
        }

        return $images;
    }

    /** @param  array<string, mixed>  $page */
    private function imagesDone(string $key, array $page): bool
    {
        foreach (array_keys($this->imagesFor($key, $page)) as $target) {
            if (! is_file($target)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function cached(string $article): ?array
    {
        $path = "{$this->cache}/{$article}.json";

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }

    private function recordFailure(string $article, string $url, mixed $response, string $what): void
    {
        $this->failed++;
        $reason = match (true) {
            $response instanceof Response => "{$what} HTTP {$response->status()}",
            $response instanceof Throwable => "{$what}: ".$response->getMessage(),
            default => "{$what} request failed",
        };

        fputcsv($this->failures, [$article, $url, $reason]);
    }

    /** @return string|null an error message, or null once the file is written */
    private function storeWebp(string $target, mixed $response): ?string
    {
        if (! $response instanceof Response) {
            return $response instanceof Throwable ? 'image: '.$response->getMessage() : 'image request failed';
        }

        if (! $response->successful()) {
            return "image HTTP {$response->status()}";
        }

        $bytes = $response->body();

        // Most of Asaxiy's CDN already serves WebP; re-encoding it would only
        // lose quality. Anything else goes through GD.
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return $this->writeAtomically($target, $bytes);
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return 'not a readable image';
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, (int) $this->option('quality'));
        $webp = ob_get_clean();

        return $ok && $webp !== '' ? $this->writeAtomically($target, $webp) : 'WebP encoding failed';
    }

    /**
     * Write then rename, so an interrupted run never leaves a truncated file
     * that the next run would mistake for a finished one.
     */
    private function writeAtomically(string $target, string $bytes): ?string
    {
        $temp = "{$target}.part";

        if (file_put_contents($temp, $bytes) === false || ! rename($temp, $target)) {
            return 'could not write file';
        }

        return null;
    }

    private function request(PendingRequest $request): PendingRequest
    {
        return $request->withUserAgent(self::USER_AGENT)->connectTimeout(10)->timeout(30)->retry(2, 2000, throw: false);
    }
}
