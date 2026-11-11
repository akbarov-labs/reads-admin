<?php

namespace App\Console\Commands;

use App\Services\ProfileSuggestions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Loads researched facts about authors and translators into "Author &
 * translator suggestions", for an admin to review and publish one by one.
 * Nothing reaches the site here.
 *
 * The file shipped with the app (database/data/profile-suggestions.json)
 * was researched on 2026-11-12 for the 300 authors with the most books and
 * every translator, from sources each entry names.
 */
#[Signature('profiles:import-suggestions
    {--file= : JSON file to read (default: database/data/profile-suggestions.json)}
    {--reopen : Put a published or rejected person back for review when the file has a new bio for them}')]
#[Description('Load researched author and translator facts for review')]
class ImportProfileSuggestions extends Command
{
    public function handle(ProfileSuggestions $suggestions): int
    {
        $file = $this->option('file') ?: database_path('data/profile-suggestions.json');
        $entries = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            $this->error("Cannot read {$file} as a JSON list.");

            return self::FAILURE;
        }

        $report = $suggestions->import($entries, reopen: (bool) $this->option('reopen'));

        foreach ($report['missing'] as $label) {
            $this->warn("  missing  {$label}");
        }

        $this->info(sprintf(
            '%d new, %d refreshed and %d reopened suggestion(s) waiting for review; %d already decided, %d with nothing found, %d not found.',
            count($report['created']), count($report['refreshed']), count($report['reopened']),
            count($report['decided']), count($report['empty']), count($report['missing']),
        ));

        return self::SUCCESS;
    }
}
