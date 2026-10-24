<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public read API is rate limited per IP, but the Next.js site sends
 * every visitor's and crawler's cache misses from one IP. Before it had a
 * bucket of its own, a Googlebot crawl used up the 120 a minute and the site
 * rendered the 429s as 500s.
 */
class PublicReadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'site-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['seo.revalidate.secret' => self::SECRET]);
    }

    public function test_strangers_are_limited_to_120_a_minute(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/categories')->assertOk();
        }

        $this->getJson('/api/categories')->assertStatus(429);
    }

    public function test_the_site_is_not_held_to_a_strangers_limit(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->withToken(self::SECRET)->getJson('/api/categories')->assertOk();
        }
    }

    public function test_strangers_do_not_use_up_the_sites_bucket(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/categories');
        }
        $this->getJson('/api/categories')->assertStatus(429);

        $this->withToken(self::SECRET)->getJson('/api/categories')->assertOk();
    }

    public function test_a_wrong_token_gets_a_strangers_limit(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withToken('wrong')->getJson('/api/categories');
        }

        $this->withToken('wrong')->getJson('/api/categories')->assertStatus(429);
    }

    public function test_an_unset_secret_lets_no_one_into_the_sites_bucket(): void
    {
        config(['seo.revalidate.secret' => null]);

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/categories');
        }

        // An empty bearer must not match an empty secret.
        $this->withToken('')->getJson('/api/categories')->assertStatus(429);
    }
}
