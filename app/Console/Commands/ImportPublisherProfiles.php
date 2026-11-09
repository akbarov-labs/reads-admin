<?php

namespace App\Console\Commands;

use App\Models\Publisher;
use App\Services\Telegram\TelegramChannel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fills publishers' pages from a reviewed JSON file: the Telegram channel,
 * the order account, contacts and the "About" text in each language, keyed
 * by slug. The channel's profile photo becomes the logo. Only blank fields
 * are filled, so what a publisher or an admin typed is never overwritten,
 * and running it twice changes nothing the second time.
 *
 * The file shipped with the app (database/data/publisher-profiles.json) was
 * gathered on 2026-11-09 from each publisher's own public channel.
 */
#[Signature('publishers:import-profiles
    {--file= : JSON file to read (default: database/data/publisher-profiles.json)}
    {--dry-run : Only show what would change}
    {--no-logos : Do not download logos from Telegram}')]
#[Description('Fill blank publisher details (channel, contacts, about text, logo) from a JSON file')]
class ImportPublisherProfiles extends Command
{
    private const FIELDS = ['telegram_channel', 'order_contact', 'website_url', 'phone', 'instagram', 'address', 'established_year'];

    private const LOCALES = ['uz', 'ru', 'en'];

    public function handle(TelegramChannel $telegram): int
    {
        $file = $this->option('file') ?: database_path('data/publisher-profiles.json');
        $profiles = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($profiles)) {
            $this->error("Cannot read {$file} as JSON.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $totals = ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'logos' => 0];

        foreach ($profiles as $slug => $profile) {
            $publisher = (new Publisher)->resolveRouteBinding((string) $slug);

            if ($publisher === null) {
                $this->warn("  missing  {$slug}");
                $totals['missing']++;

                continue;
            }

            $changed = $this->fill($publisher, (array) $profile);

            if (! $this->option('no-logos') && blank($publisher->logo_path) && filled($publisher->telegram_channel)) {
                $logo = $dry ? 'telegram' : $telegram->saveChannelPhoto($publisher->telegram_channel, 'publishers');

                if ($logo !== null) {
                    $publisher->logo_path = $dry ? null : $logo;
                    $changed[] = 'logo';
                    $totals['logos']++;
                }
            }

            if ($changed === []) {
                $totals['unchanged']++;

                continue;
            }

            if (! $dry) {
                $publisher->save();
            }

            $this->line("  updated  {$publisher->slug}: ".implode(', ', $changed));
            $totals['updated']++;
        }

        $this->info(($dry ? '[dry run] ' : '')."{$totals['updated']} updated ({$totals['logos']} logos), {$totals['unchanged']} already filled, {$totals['missing']} not found.");

        return self::SUCCESS;
    }

    /**
     * Sets the profile's values on the publisher's blank fields.
     *
     * @param  array<string, mixed>  $profile
     * @return list<string> the fields that changed
     */
    private function fill(Publisher $publisher, array $profile): array
    {
        $changed = [];

        foreach (self::FIELDS as $field) {
            $value = $profile[$field] ?? null;

            if (filled($value) && blank($publisher->{$field})) {
                $publisher->{$field} = $field === 'established_year' ? (int) $value : trim((string) $value);
                $changed[] = $field;
            }
        }

        foreach (self::LOCALES as $locale) {
            $bio = trim((string) ($profile['bio'][$locale] ?? ''));

            if ($bio !== '' && blank($publisher->getTranslation('bio', $locale, false))) {
                $publisher->setTranslation('bio', $locale, $bio);
                $changed[] = "bio.{$locale}";
            }
        }

        return $changed;
    }
}
