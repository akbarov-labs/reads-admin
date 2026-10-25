<?php

namespace App\Services;

use App\Models\Library;
use App\Models\LibraryHolding;
use App\Models\LibraryImport;
use App\Models\LibraryImportRow;
use App\Models\User;
use App\Support\UzbekText;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * A library's book list, from an Excel or CSV file to library_holdings, in
 * two steps: read() matches every line to a book and keeps the result for
 * the owner to check, and apply() writes what they accepted.
 *
 * Owners bring their own files, so columns are found by their header in
 * any of the three languages, and a file with no header is read as
 * title, author, ISBN, copies, on the shelf.
 */
class LibraryImporter
{
    public const MAX_ROWS = 5000;

    /** Header words, as UzbekText::key() spells them, for each column. */
    private const HEADERS = [
        'title' => ['nomi', 'kitob', 'kitobnomi', 'kitobningnomi', 'asar', 'asarnomi', 'nom', 'title', 'book', 'booktitle', 'nazvanie', 'nazvaniye', 'kniga'],
        'author' => ['muallif', 'muallifi', 'mualliflar', 'author', 'authors', 'avtor', 'avtory'],
        'isbn' => ['isbn'],
        'copies' => ['soni', 'nusxa', 'nusxasoni', 'nusxalar', 'jami', 'miqdori', 'miqdor', 'copies', 'count', 'quantity', 'kolichestvo', 'kolvo', 'ekzemplyarov'],
        'available' => ['mavjud', 'bor', 'javonda', 'qolgan', 'available', 'onshelf', 'vnalichii', 'dostupno'],
    ];

    /** The order a header-less file is read in, and the template's columns. */
    private const DEFAULT_ORDER = ['title', 'author', 'isbn', 'copies', 'available'];

    public const TEMPLATE_HEADERS = ['Nomi', 'Muallif', 'ISBN', 'Soni', 'Mavjud'];

    public function __construct(
        private readonly LibraryBookMatcher $matcher,
        private readonly SiteRevalidator $revalidator,
    ) {}

    /**
     * Reads the file into a new import, waiting for review. An earlier
     * import of this library that was never applied is dropped: the owner
     * has started over.
     */
    public function read(Library $library, string $path, string $filename, ?User $user = null): LibraryImport
    {
        $lines = $this->lines($path, $filename);

        if ($lines === []) {
            throw new RuntimeException('The file has no books in it.');
        }

        return DB::transaction(function () use ($library, $lines, $filename, $user) {
            $library->imports()->where('status', LibraryImport::REVIEW)->delete();

            $import = $library->imports()->create([
                'user_id' => $user?->id,
                'filename' => mb_substr($filename, 0, 255),
                'status' => LibraryImport::REVIEW,
            ]);

            foreach ($lines as $line) {
                $match = $this->matcher->match($line['title'], $line['author'], $line['isbn']);

                $import->rows()->create([
                    ...$line,
                    ...$match,
                    'candidate_ids' => $match['candidate_ids'] ?: null,
                    'decision' => in_array($match['match'], [LibraryImportRow::MATCH_ISBN, LibraryImportRow::MATCH_EXACT], true)
                        ? LibraryImportRow::ACCEPT
                        : LibraryImportRow::PENDING,
                ]);
            }

            return $import;
        });
    }

    /**
     * Writes the accepted lines to the library's books. Copies come from
     * the file; what is on the shelf too, when the file says, and otherwise
     * every copy for a new book and no change for one already listed. Two
     * lines for one book add up. With $removeMissing, books on the list
     * that the file does not name are taken off it.
     *
     * One ping to the site at the end, not one per row.
     *
     * @return array{added: int, updated: int, removed: int}
     */
    public function apply(LibraryImport $import, bool $removeMissing = false): array
    {
        $library = $import->library;

        $accepted = $import->rows()
            ->where('decision', LibraryImportRow::ACCEPT)
            ->whereNotNull('book_id')
            ->orderBy('row_number')
            ->get()
            ->groupBy('book_id');

        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0];

        DB::transaction(function () use ($import, $library, $accepted, $removeMissing, &$counts) {
            LibraryHolding::withoutEvents(function () use ($library, $accepted, $removeMissing, &$counts) {
                $existing = $library->holdings()->get()->keyBy('book_id');

                foreach ($accepted as $bookId => $lines) {
                    $total = max(1, (int) $lines->sum(fn (LibraryImportRow $line) => $line->copies ?? 1));
                    $given = $lines->every(fn (LibraryImportRow $line) => $line->available !== null)
                        ? (int) $lines->sum('available')
                        : null;
                    $edition = $lines->pluck('edition_id')->filter()->first();
                    $holding = $existing->get($bookId);

                    if ($holding === null) {
                        $library->holdings()->create([
                            'book_id' => $bookId,
                            'edition_id' => $edition,
                            'copies_total' => $total,
                            'copies_available' => min($given ?? $total, $total),
                        ]);
                        $counts['added']++;

                        continue;
                    }

                    $holding->update([
                        'edition_id' => $holding->edition_id ?? $edition,
                        'copies_total' => $total,
                        'copies_available' => min($given ?? $holding->copies_available, $total),
                    ]);
                    $counts['updated']++;
                }

                if ($removeMissing) {
                    $counts['removed'] = $library->holdings()->whereNotIn('book_id', $accepted->keys())->delete();
                }
            });

            $import->update(['status' => LibraryImport::APPLIED, 'applied_at' => now()]);
        });

        $library->touchHoldings();
        $this->revalidator->library($library->slug);

        return $counts;
    }

    /** An empty template with the headers the reader looks for, and one example row. */
    public function template(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::TEMPLATE_HEADERS));
        $writer->addRow(Row::fromValues(["O'tkan kunlar", 'Abdulla Qodiriy', '978-9943-00-000-0', 2, 1]));
        $writer->close();
    }

    /**
     * @return list<array{row_number: int, title: ?string, author: ?string, isbn: ?string, copies: ?int, available: ?int}>
     */
    private function lines(string $path, string $filename): array
    {
        $reader = $this->reader($path, $filename);
        $reader->open($path);

        $columns = null;
        $lines = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = array_map(fn ($value) => $this->text($value), $row->toArray());

                    if (array_filter($cells, fn (?string $cell) => $cell !== null) === []) {
                        continue;
                    }

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        if ($columns !== null) {
                            continue;
                        }

                        $columns = array_flip(self::DEFAULT_ORDER);
                    }

                    $line = $this->line($cells, $columns, (int) $number);

                    if ($line['title'] === null && $line['isbn'] === null) {
                        continue;
                    }

                    $lines[] = $line;

                    if (count($lines) > self::MAX_ROWS) {
                        throw new RuntimeException('The file has more than '.number_format(self::MAX_ROWS).' books. Split it into smaller files.');
                    }
                }

                // The first sheet is the list; later ones are notes.
                break;
            }
        } finally {
            $reader->close();
        }

        return $lines;
    }

    private function reader(string $path, string $filename): ReaderInterface
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => $this->csvReader($path),
            default => throw new RuntimeException('Upload an Excel (.xlsx) or CSV file.'),
        };
    }

    /**
     * Excel saves CSV with a semicolon wherever the decimal mark is a comma,
     * as it is in Uzbek and Russian settings, so the delimiter is whichever
     * of the three the first line has most of.
     */
    private function csvReader(string $path): CsvReader
    {
        $handle = fopen($path, 'r');
        $firstLine = (string) fgets($handle);
        fclose($handle);

        $found = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($found);

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = (string) array_key_first($found);

        return new CsvReader($options);
    }

    /**
     * Column positions by header, or null when this row is not a header
     * (no title column is named in it).
     *
     * @param  list<?string>  $cells
     * @return array<string, int>|null
     */
    private function columns(array $cells): ?array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $key = UzbekText::key((string) $cell);

            foreach (self::HEADERS as $column => $words) {
                if (! isset($columns[$column]) && in_array($key, $words, true)) {
                    $columns[$column] = $index;
                }
            }
        }

        return isset($columns['title']) ? $columns : null;
    }

    /**
     * @param  list<?string>  $cells
     * @param  array<string, int>  $columns
     * @return array{row_number: int, title: ?string, author: ?string, isbn: ?string, copies: ?int, available: ?int}
     */
    private function line(array $cells, array $columns, int $number): array
    {
        $cell = fn (string $column): ?string => isset($columns[$column]) ? ($cells[$columns[$column]] ?? null) : null;

        return [
            'row_number' => $number,
            'title' => $this->limit(UzbekText::clean($cell('title')), 500),
            'author' => $this->limit(UzbekText::clean($cell('author')), 500),
            'isbn' => $this->limit($cell('isbn'), 32),
            'copies' => $this->count($cell('copies')),
            'available' => $this->count($cell('available')),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return null;
        }

        // A spreadsheet holds a long ISBN as a float: 9789943000000.0.
        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function count(?string $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return max(0, min(999, (int) $value));
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
