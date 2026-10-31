<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SoftlyBookResource\Pages;
use App\Filament\Support\BookPicker;
use App\Models\Book;
use App\Models\LibraryImportRow;
use App\Models\SoftlyBook;
use App\Services\Softly\SoftlyNewBooks;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The books the Softly libraries (kutubxonam.uz) hold that the sync could
 * not place in the catalogue on its own, for an admin to settle.
 *
 * "To check" is the work: titles like one or more catalogue books but not
 * exactly any, where a guess could make a duplicate or a wrong "bor". Each
 * is linked to its book, made a new one, or skipped. The other tabs show
 * what was decided, and take back a book made from Softly.
 *
 * One row per title and author: a book in three libraries is three Softly
 * rows, and every decision here is for all of them (SoftlyNewBooks).
 */
class SoftlyBookResource extends Resource
{
    protected static ?string $model = SoftlyBook::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Softly matches';

    protected static ?string $modelLabel = 'Softly title';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Pages\ListSoftlyBooks::countTitles(Pages\ListSoftlyBooks::TO_CHECK);

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('At the library')
                    ->description(fn (SoftlyBook $row) => $row->author)
                    ->searchable(['name', 'author'])
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('libraries')
                    ->label('Where')
                    ->getStateUsing(fn (SoftlyBook $row) => self::where($row)),
                Tables\Columns\TextColumn::make('book')
                    ->label('Book on kitoblarim.uz')
                    ->getStateUsing(fn (SoftlyBook $row) => self::bookLabel($row))
                    ->description(fn (SoftlyBook $row) => self::matchLabel($row))
                    ->url(fn (SoftlyBook $row) => $row->book_id ? BookResource::getUrl('edit', ['record' => $row->book_id]) : null)
                    ->placeholder('None')
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('link')
                    ->label('Same book')
                    ->icon('heroicon-m-link')
                    ->color('success')
                    ->visible(fn (SoftlyBook $row) => $row->book_id === null)
                    ->modalHeading(fn (SoftlyBook $row) => $row->name)
                    ->modalDescription(fn (SoftlyBook $row) => $row->author)
                    ->modalSubmitActionLabel('Link')
                    ->form(fn (SoftlyBook $row) => [
                        Forms\Components\Radio::make('candidate')
                            ->label('It is')
                            ->options(Book::with('authors')->findMany($row->candidate_ids ?? [])
                                ->mapWithKeys(fn (Book $book) => [$book->id => BookPicker::label($book)])
                                ->all())
                            ->default(($row->candidate_ids ?? [])[0] ?? null)
                            ->visible(filled($row->candidate_ids)),
                        Forms\Components\Select::make('other')
                            ->label(filled($row->candidate_ids) ? 'Or another book' : 'Book')
                            ->helperText('Search by title, author or ISBN.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => BookPicker::search($search))
                            ->getOptionLabelUsing(fn ($value): ?string => BookPicker::labelFor($value)),
                    ])
                    ->action(function (SoftlyBook $row, array $data, SoftlyNewBooks $newBooks) {
                        $bookId = $data['other'] ?? $data['candidate'] ?? null;

                        if ($bookId === null) {
                            return;
                        }

                        $newBooks->link($row, (int) $bookId);
                        self::done('Linked');
                    }),
                Tables\Actions\Action::make('create')
                    ->label('New book')
                    ->icon('heroicon-m-plus')
                    ->color('gray')
                    ->visible(fn (SoftlyBook $row) => $row->book_id === null)
                    ->modalHeading('Make it a new book')
                    ->modalDescription('One edition with no ISBN. If an ISBN for it comes later, from the bot or Asaxiy, it joins this book.')
                    ->modalSubmitActionLabel('Make the book')
                    ->fillForm(fn (SoftlyBook $row) => ['title' => $row->name, 'author' => $row->author])
                    ->form([
                        Forms\Components\TextInput::make('title')->required()->maxLength(500),
                        Forms\Components\TextInput::make('author')
                            ->helperText('An author already in the catalogue is found under any spelling.')
                            ->maxLength(500),
                    ])
                    ->action(function (SoftlyBook $row, array $data, SoftlyNewBooks $newBooks) {
                        $newBooks->create($row, $data['title'], $data['author'] ?? null);
                        self::done('Book made');
                    }),
                Tables\Actions\Action::make('skip')
                    ->label('Skip')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->visible(fn (SoftlyBook $row) => $row->book_id === null && ! $row->skipped)
                    ->action(fn (SoftlyBook $row, SoftlyNewBooks $newBooks) => $newBooks->skip($row)),
                Tables\Actions\Action::make('unskip')
                    ->label('Back to check')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (SoftlyBook $row) => $row->book_id === null && $row->skipped)
                    ->action(fn (SoftlyBook $row, SoftlyNewBooks $newBooks) => $newBooks->unskip($row)),
                Tables\Actions\Action::make('remove')
                    ->label('Remove the book')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->visible(fn (SoftlyBook $row) => $row->match === SoftlyBook::MATCH_CREATED)
                    ->requiresConfirmation()
                    ->modalDescription('Deletes the book made from this title, and skips the title so it is not made again. For a magazine, a notebook, or a title made twice.')
                    ->action(function (SoftlyBook $row, SoftlyNewBooks $newBooks) {
                        if (! $newBooks->remove($row)) {
                            Notification::make()
                                ->title('Not removed')
                                ->body('The book has been edited since it was made (another edition, or an ISBN). Delete it under Books if it should go.')
                                ->warning()
                                ->send();

                            return;
                        }

                        self::done('Removed and skipped');
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('createAll')
                    ->label('Make new books')
                    ->icon('heroicon-m-plus')
                    ->requiresConfirmation()
                    ->modalDescription('Each selected title becomes a book under its library title and author.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $rows, SoftlyNewBooks $newBooks) {
                        $rows->whereNull('book_id')->each(fn (SoftlyBook $row) => $newBooks->create($row, $row->name, $row->author));
                        self::done('Books made');
                    }),
                Tables\Actions\BulkAction::make('skipAll')
                    ->label('Skip')
                    ->icon('heroicon-m-x-mark')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $rows, SoftlyNewBooks $newBooks) => $rows->whereNull('book_id')->each(fn (SoftlyBook $row) => $newBooks->skip($row))),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nothing here')
            ->emptyStateDescription('Titles show up after the Softly sync has read the libraries.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSoftlyBooks::route('/'),
        ];
    }

    /** "Mehr 3 · Kitobqand 1": copies of this title in each library. */
    private static function where(SoftlyBook $row): string
    {
        return SoftlyBook::query()
            ->where('name', $row->name)
            ->where('author', $row->author)
            ->get(['softly_library', 'stocks'])
            ->map(fn (SoftlyBook $same) => (SoftlyBook::LIBRARIES[$same->softly_library] ?? "#{$same->softly_library}").' '.$same->copies())
            ->implode(' · ');
    }

    private static function bookLabel(SoftlyBook $row): ?string
    {
        $book = $row->book ?? ($row->book_id === null && filled($row->candidate_ids) ? Book::with('authors')->find($row->candidate_ids[0]) : null);

        return $book ? BookPicker::label($book) : null;
    }

    private static function matchLabel(SoftlyBook $row): ?string
    {
        if ($row->book_id === null) {
            $count = count($row->candidate_ids ?? []);

            return match (true) {
                $row->skipped => 'Skipped',
                $count > 1 => "Closest of {$count}. Not linked",
                $count === 1 => 'Close, not exact. Not linked',
                default => 'Nothing like it. Made a book tonight',
            };
        }

        return match ($row->match) {
            SoftlyBook::MATCH_CREATED => 'Made from this title',
            LibraryImportRow::MATCH_MANUAL => 'Linked by you',
            default => 'Same title and author',
        };
    }

    private static function done(string $title): void
    {
        Notification::make()
            ->title($title)
            ->body('Library lists on the site change at the next sync, within half an hour (08:00–21:00).')
            ->success()
            ->send();
    }
}
