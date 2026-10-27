<?php

namespace App\Console\Commands;

use App\Services\Softly\SoftlySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('softly:sync
    {--full : Read every page and take away books Softly no longer lists (nightly)}')]
#[Description('Bring the libraries that run on Softly (kutubxonam.uz) up to date')]
class SyncSoftly extends Command
{
    public function handle(SoftlySync $sync): int
    {
        if (! config('services.softly.enabled')) {
            $this->info('Softly sync is off. Set SOFTLY_SYNC_ENABLED=true to turn it on.');

            return self::SUCCESS;
        }

        $report = $sync->run((bool) $this->option('full'));

        if (isset($report['skipped'])) {
            $this->info("Skipped: {$report['skipped']}.");

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s run, %d request(s): %d added, %d changed, %d removed, %d newly matched.',
            ucfirst($report['mode']), $report['requests'], $report['added'], $report['changed'], $report['removed'], $report['matched'],
        ));

        if ($report['mode'] === 'full') {
            $this->line("Copies whose status changed without their book's updatedAt: {$report['missed_by_quick']}.");

            foreach ($report['locations'] as $location => $info) {
                $this->line(sprintf('  location %d: %d copies, %s', $location, $info['copies'], $info['library'] ?? 'not linked to a library'));
            }
        }

        foreach ($report['holdings'] as $slug => $done) {
            $this->line("  {$slug}: {$done['added']} added, {$done['updated']} updated, {$done['removed']} removed.");
        }

        if ($report['error'] !== null) {
            $this->error($report['error']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
