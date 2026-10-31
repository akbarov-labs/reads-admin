<?php

namespace App\Console\Commands;

use App\Services\Softly\SoftlyNewBooks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('softly:add-books
    {--dry-run : Only say what would be made}')]
#[Description('Make catalogue books of the Softly titles the catalogue has nothing like (the nightly sync does this too)')]
class AddSoftlyBooks extends Command
{
    public function handle(SoftlyNewBooks $newBooks): int
    {
        if ($this->option('dry-run')) {
            $preview = $newBooks->preview();

            $this->line("{$preview['books']} book(s) would be made, from {$preview['rows']} Softly row(s).");

            foreach ($preview['sample'] as $title) {
                $this->line("  {$title}");
            }

            $this->line(count($preview['new_authors']).' author name(s) are not in the catalogue under any spelling. The most frequent:');

            foreach (array_slice($preview['new_authors'], 0, 25, true) as $name => $books) {
                $this->line("  {$name}: {$books}");
            }

            if ($preview['respelled'] !== []) {
                $this->line(count($preview['respelled']).' name(s) taken as a typo of an author the catalogue has:');

                foreach ($preview['respelled'] as $name => $author) {
                    $this->line("  {$name} → {$author}");
                }
            }

            return self::SUCCESS;
        }

        $done = $newBooks->createWaiting();

        $this->info("{$done['created']} book(s) made, {$done['found']} linked to a book the catalogue already had. Library lists fill in at the next sync.");

        return self::SUCCESS;
    }
}
