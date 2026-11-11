<?php

namespace App\Console\Commands;

use App\Services\PublisherSuggestions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Loads details found for publishers into Publisher suggestions, for an
 * admin to review and publish one by one. Nothing reaches the site here.
 *
 * The file shipped with the app (database/data/publisher-suggestions.json)
 * was gathered on 2026-11-10 from publishers' channels, websites and
 * Instagram, with how sure each find is and why.
 */
#[Signature('publishers:import-suggestions
    {--file= : JSON file to read (default: database/data/publisher-suggestions.json)}
    {--no-logos : Do not download channel photos from Telegram}
    {--reopen : Put a published or rejected publisher back for review when the file has a new About text for it}')]
#[Description('Load found publisher details (channel, contacts, about text) for review')]
class ImportPublisherSuggestions extends Command
{
    public function handle(PublisherSuggestions $suggestions): int
    {
        $file = $this->option('file') ?: database_path('data/publisher-suggestions.json');
        $entries = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($entries)) {
            $this->error("Cannot read {$file} as JSON.");

            return self::FAILURE;
        }

        $report = $suggestions->import($entries, logos: ! $this->option('no-logos'), reopen: (bool) $this->option('reopen'));

        foreach ($report['missing'] as $slug) {
            $this->warn("  missing  {$slug}");
        }

        $this->info(sprintf(
            '%d new, %d refreshed and %d reopened suggestion(s) waiting for review; %d already decided, %d with nothing found, %d not found.',
            count($report['created']), count($report['refreshed']), count($report['reopened']), count($report['decided']), count($report['empty']), count($report['missing']),
        ));

        return self::SUCCESS;
    }
}
