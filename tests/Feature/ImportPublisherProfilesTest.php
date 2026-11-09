<?php

namespace Tests\Feature;

use App\Filament\Resources\PublisherResource;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * Publisher pages filled from their Telegram channels: blank fields only,
 * the channel photo as the logo, and nothing changes on a second run.
 */
class ImportPublisherProfilesTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::fake([
            't.me/hilolnashr' => Http::response('<div class="tgme_page_photo"><img class="tgme_page_photo_image" src="https://cdn4.telesco.pe/file/logo.jpg?a=1&amp;b=2"></div>'),
            't.me/*' => Http::response('<div class="tgme_page">no photo</div>'),
            'cdn4.telesco.pe/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'profiles');
        file_put_contents($this->file, json_encode([
            'hilol-nashr' => [
                'telegram_channel' => 'hilolnashr',
                'order_contact' => 'hilol_reception',
                'website_url' => 'https://hilol.uz',
                'phone' => '+998 71 217 59 99',
                'instagram' => 'hilolnashr',
                'address' => 'Toshkent',
                'established_year' => 2015,
                'bio' => ['uz' => 'Diniy va badiiy kitoblar.', 'ru' => 'Религиозные и художественные книги.', 'en' => 'Religious books and fiction.'],
            ],
            'akademnashr' => [
                'telegram_channel' => 'akademnashr',
                'phone' => '+998 99 433 00 00',
                'bio' => ['uz' => 'Yangi matn', 'ru' => 'Учебники.'],
            ],
            'no-such-publisher' => ['telegram_channel' => 'nobody'],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_it_fills_blank_fields_and_takes_the_logo_from_the_channel(): void
    {
        $hilol = $this->publisher('Hilol-nashr');

        $this->artisan('publishers:import-profiles', ['--file' => $this->file])
            ->expectsOutputToContain('missing  no-such-publisher')
            ->assertSuccessful();

        $hilol->refresh();
        $this->assertSame('hilolnashr', $hilol->telegram_channel);
        $this->assertSame('hilol_reception', $hilol->order_contact);
        $this->assertSame('https://hilol.uz', $hilol->website_url);
        $this->assertSame('+998 71 217 59 99', $hilol->phone);
        $this->assertSame('hilolnashr', $hilol->instagram);
        $this->assertSame(2015, $hilol->established_year);
        $this->assertSame('Religious books and fiction.', $hilol->getTranslation('bio', 'en'));
        $this->assertStringStartsWith('publishers/', $hilol->logo_path);
        Storage::disk('public')->assertExists($hilol->logo_path);
        Http::assertSent(fn ($request) => $request->url() === 'https://cdn4.telesco.pe/file/logo.jpg?a=1&b=2');
    }

    public function test_what_someone_already_typed_is_kept(): void
    {
        $akadem = $this->publisher('Akademnashr');
        $akadem->update(['phone' => '+998 71 000 00 00', 'bio' => ['uz' => 'Oʻzimiz yozgan matn']]);

        $this->artisan('publishers:import-profiles', ['--file' => $this->file])->assertSuccessful();

        $akadem->refresh();
        $this->assertSame('+998 71 000 00 00', $akadem->phone);
        $this->assertSame('Oʻzimiz yozgan matn', $akadem->getTranslation('bio', 'uz'));
        $this->assertSame('Учебники.', $akadem->getTranslation('bio', 'ru'));
        $this->assertSame('akademnashr', $akadem->telegram_channel);
        // Its channel page has no photo, so there is no logo to take.
        $this->assertNull($akadem->logo_path);
    }

    public function test_a_second_run_and_a_dry_run_change_nothing(): void
    {
        $hilol = $this->publisher('Hilol-nashr');

        $this->artisan('publishers:import-profiles', ['--file' => $this->file, '--dry-run' => true])
            ->expectsOutputToContain('[dry run] 1 updated')
            ->assertSuccessful();
        $this->assertNull($hilol->fresh()->telegram_channel);

        $this->artisan('publishers:import-profiles', ['--file' => $this->file])->assertSuccessful();
        $logo = $hilol->fresh()->logo_path;

        $this->artisan('publishers:import-profiles', ['--file' => $this->file])
            ->expectsOutputToContain('0 updated (0 logos), 1 already filled')
            ->assertSuccessful();
        $this->assertSame($logo, $hilol->fresh()->logo_path);
    }

    public function test_the_shipped_file_is_valid(): void
    {
        $profiles = json_decode((string) file_get_contents(database_path('data/publisher-profiles.json')), true);

        $this->assertIsArray($profiles);
        $this->assertNotEmpty($profiles);

        foreach ($profiles as $slug => $profile) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $profile['telegram_channel'], $slug);
            $this->assertNotEmpty($profile['bio']['uz'] ?? null, "{$slug} has no Uzbek text");

            if (filled($profile['instagram'] ?? null)) {
                $this->assertSame($profile['instagram'], PublisherResource::instagramUsername($profile['instagram']), $slug);
            }
        }
    }

    public function test_an_instagram_link_becomes_a_username(): void
    {
        $this->assertSame('hilol.nashr', PublisherResource::instagramUsername('https://www.instagram.com/hilol.nashr/?igsh=abc'));
        $this->assertSame('hilolnashr', PublisherResource::instagramUsername('@hilolnashr'));
        $this->assertNull(PublisherResource::instagramUsername('not a username'));
    }
}
