<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the public Next.js site that a profile changed, so its cached pages
 * (and the sitemap built from the same data) rebuild immediately instead of
 * waiting out the ISR window. Add a book at 10:00 and it is in the sitemap
 * at 10:00, not 10:01.
 *
 * Deliberately best-effort: a site that is down or slow must never stop an
 * admin from saving. Failures are logged, and the ISR window remains as the
 * backstop that eventually picks the change up anyway.
 */
class SiteRevalidator
{
    public function translator(?string $slug): void
    {
        $this->ping(['type' => 'translator', 'slug' => $slug]);
    }

    /**
     * A reviewer's own page. `type` tells the site which kind of profile the
     * slug names — without it, a taqrizchi and a translator who happen to
     * share a slug would invalidate each other's page and neither their own.
     */
    public function taqrizchi(?string $slug): void
    {
        $this->ping(['type' => 'taqrizchi', 'slug' => $slug]);
    }

    /**
     * One book's page, which carries its own taqrizlar. Books are no longer
     * reached only through a translator profile, so a book change is not
     * necessarily a translator change.
     */
    public function book(int|string|null $id): void
    {
        $this->ping(['type' => 'book', 'id' => (string) $id]);
    }

    /**
     * A library's own page. Like every ping it also drops the site's
     * collection tags, the books' pages among them, so one ping after a
     * holdings import updates every book it touched.
     */
    public function library(?string $slug): void
    {
        $this->ping(['type' => 'library', 'slug' => $slug]);
    }

    public function everything(): void
    {
        $this->ping([]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ping(array $payload): void
    {
        $url = config('seo.revalidate.url');
        $secret = config('seo.revalidate.secret');

        // Unconfigured (local dev, or before the secret is provisioned) is a
        // normal state, not an error worth logging on every save.
        if (blank($url) || blank($secret)) {
            return;
        }

        try {
            $response = Http::withToken($secret)
                ->connectTimeout(2)
                ->timeout((int) config('seo.revalidate.timeout', 5))
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('Site revalidation was rejected.', [
                    'status' => $response->status(),
                    'payload' => $payload,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Site revalidation failed.', [
                'message' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }
}
