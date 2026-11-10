<?php

namespace App\Console\Commands;

use App\Services\SiteRevalidator;
use App\Services\Stores\AsaxiyFeed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stores:asaxiy
    {--limit= : Read at most this many pages (default: services.asaxiy.pages_per_run)}')]
#[Description('Read price and stock from the asaxiy.uz pages our editions came from')]
class ReadAsaxiy extends Command
{
    public function handle(AsaxiyFeed $feed): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $read = $feed->read($limit);

        $this->table(['', 'Pages'], [
            ['due', $read['due']],
            ['read', $read['read']],
            ['in stock', $read['in_stock']],
            ['sold out', $read['sold_out']],
            ['gone', $read['gone']],
        ]);

        if ($read['stopped'] !== null) {
            $this->warn("Stopped early: {$read['stopped']}");
        }

        if ($read['read'] > 0) {
            // Prices and "in stock" show on book pages.
            app(SiteRevalidator::class)->everything();
        }

        $this->info(sprintf('Read %d Asaxiy pages: %d in stock, %d sold out, %d gone.', $read['read'], $read['in_stock'], $read['sold_out'], $read['gone']));

        return self::SUCCESS;
    }
}
