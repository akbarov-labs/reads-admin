<?php

namespace App\Console\Commands;

use App\Support\Asaxiy\AsaxiyPage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('asaxiy:categories
    {--cache= : Where listings go. Defaults to storage/app/asaxiy/categories}
    {--delay=1500 : Milliseconds to wait between listing pages}
    {--refresh : Crawl categories again even if already saved}')]
#[Description('Record which Asaxiy shelves (genres, audiences, bestseller lists) each book is on')]
class ScrapeAsaxiyCategories extends Command
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    /** A listing has ~25 products a page; nothing on the site comes near this. */
    private const MAX_PAGES = 400;

    public function handle(): int
    {
        $cache = rtrim($this->option('cache') ?: storage_path('app/asaxiy/categories'), '/');

        if (! is_dir($cache) && ! mkdir($cache, 0775, true)) {
            $this->error("Cannot create {$cache}");

            return self::FAILURE;
        }

        foreach (AsaxiyPage::CATEGORIES as $path => $category) {
            $file = "{$cache}/".str_replace('/', '--', $path).'.json';

            if (is_file($file) && ! $this->option('refresh')) {
                $this->line("  {$category['name']}: already saved");

                continue;
            }

            $products = [];

            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $url = "https://asaxiy.uz/product/knigi/{$path}".($page > 1 ? "/page={$page}" : '');

                try {
                    $response = Http::withUserAgent(self::USER_AGENT)->connectTimeout(10)->timeout(30)->retry(3, 5000, throw: false)->get($url);
                } catch (Throwable $e) {
                    $this->error("  {$category['name']}: {$e->getMessage()} — stopping; run again to resume from this category.");

                    return self::FAILURE;
                }

                if (! $response->successful()) {
                    // Past the last page the site answers 404; anything else
                    // is trouble, and a half-crawled category is not saved.
                    if ($response->status() === 404 && $page > 1) {
                        break;
                    }

                    $this->error("  {$category['name']}: HTTP {$response->status()} on page {$page} — stopping; run again to resume.");

                    return self::FAILURE;
                }

                $found = AsaxiyPage::listingProducts($response->body());
                $new = array_diff($found, $products);

                // An out-of-range page may repeat the last one instead of
                // 404ing, so "nothing new" is the end too.
                if ($new === []) {
                    break;
                }

                array_push($products, ...array_values($new));
                usleep((int) $this->option('delay') * 1000);
            }

            file_put_contents($file, json_encode([
                'path' => $path,
                'name' => $category['name'],
                'parent' => $category['parent'],
                'fetched_at' => now()->toAtomString(),
                'products' => $products,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            $this->info("  {$category['name']}: ".count($products).' books');
        }

        return self::SUCCESS;
    }
}
