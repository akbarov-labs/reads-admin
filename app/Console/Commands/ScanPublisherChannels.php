<?php

namespace App\Console\Commands;

use App\Models\Publisher;
use App\Services\Telegram\ChannelScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('announcements:scan
    {--publisher= : Only this publisher (id or slug)}
    {--recheck : Instead of reading channels, reject the scan\'s pending drafts that no longer look like announcements}')]
#[Description('Read publishers\' Telegram channels and make drafts from posts that look like new books or pre-orders')]
class ScanPublisherChannels extends Command
{
    public function handle(ChannelScanner $scanner): int
    {
        if ($this->option('recheck')) {
            $rejected = $scanner->rejectStaleDrafts();

            foreach ($rejected as $title) {
                $this->line("  rejected: {$title}");
            }

            $this->line(count($rejected).' pending draft(s) rejected.');

            return self::SUCCESS;
        }

        $only = null;

        if (filled($this->option('publisher'))) {
            $only = (new Publisher)->resolveRouteBinding($this->option('publisher'));

            if ($only === null || blank($only->telegram_channel)) {
                $this->error('No such publisher, or it has no Telegram channel.');

                return self::FAILURE;
            }
        }

        $report = $scanner->scanAll($only);

        $this->line(sprintf(
            '%d channel(s) read: %d new post(s), %d draft(s) waiting for review.',
            $report['channels'], $report['posts'], $report['drafts'],
        ));

        foreach ($report['errors'] as $channel => $error) {
            $this->error("@{$channel}: {$error}");
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
