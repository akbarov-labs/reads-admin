<?php

namespace App\Console\Commands;

use App\Enums\BookProblem;
use App\Models\Book;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('books:quality')]
#[Description('Count published books with a missing cover, description, author and the like, before deciding how to fix them. Reads only.')]
class BookQuality extends Command
{
    private const EXAMPLES = 10;

    public function handle(): int
    {
        $published = Book::approved()->count();

        $ids = collect(BookProblem::cases())->mapWithKeys(fn (BookProblem $problem) => [
            $problem->value => Book::approved()->tap($problem->apply(...))->orderBy('books.id')->pluck('books.id'),
        ]);

        $this->table(
            ['Problem', 'Books', 'Share', 'Examples (book IDs)'],
            collect(BookProblem::cases())->map(fn (BookProblem $problem) => [
                $problem->getLabel(),
                $ids[$problem->value]->count(),
                self::share($ids[$problem->value]->count(), $published),
                $ids[$problem->value]->take(self::EXAMPLES)->implode(', '),
            ])->all(),
        );

        $problemsPerBook = $ids->flatten()->countBy();
        $any = $problemsPerBook->count();
        $several = $problemsPerBook->filter(fn (int $count) => $count >= 2)->count();

        $this->line("{$published} published books. {$any} have at least one problem (".self::share($any, $published)."), {$several} have two or more (".self::share($several, $published).').');
        $this->line('Work through them in Books → Filters → Needs attention.');

        return self::SUCCESS;
    }

    private static function share(int $count, int $of): string
    {
        return $of === 0 ? '—' : round(100 * $count / $of, 1).'%';
    }
}
