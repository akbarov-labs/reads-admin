<?php

namespace App\Console\Commands;

use App\Support\SearchKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reads:search-keys')]
#[Description('Recompute the search_key of every book, author, publisher and translator, e.g. after a bulk update or a change to UzbekText')]
class RebuildSearchKeys extends Command
{
    public function handle(): int
    {
        foreach (SearchKeys::rebuild() as $table => $changed) {
            $this->line("{$table}: {$changed} updated");
        }

        return self::SUCCESS;
    }
}
