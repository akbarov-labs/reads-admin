<?php

namespace App\Filament\Resources\LibraryResource\Pages;

use App\Filament\Resources\LibraryResource;
use App\Filament\Support\BookPicker;
use App\Models\Book;
use App\Models\Library;
use App\Models\LibraryImport;
use App\Models\LibraryImportRow;
use App\Services\LibraryImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Upload a library's book list, check what the matcher found, apply it.
 *
 * With no import waiting, the page is the upload form. Once a file is
 * read, it is the review: every line, the book it was matched to, and a
 * decision. Lines matched by ISBN or exact title are accepted already; the
 * owner settles the rest, then applies the accepted ones to the list.
 *
 * @property Library $record
 */
class ImportHoldings extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = LibraryResource::class;

    protected static string $view = 'filament.resources.library-resource.pages.import-holdings';

    /** @var array<string, mixed> */
    public ?array $upload = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(LibraryResource::canEdit($this->record), 403);

        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Import books';
    }

    public function getSubheading(): ?string
    {
        return $this->record->name;
    }

    public function pendingImport(): ?LibraryImport
    {
        return $this->record->imports()->where('status', LibraryImport::REVIEW)->latest('id')->first();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('upload')
            ->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('Your book list')
                    ->helperText('Excel (.xlsx) or CSV. The first row names the columns: Nomi, Muallif, ISBN, Soni, Mavjud. Only Nomi is required. Download the template to see the layout.')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                    ])
                    ->maxSize(10240)
                    ->storeFiles(false)
                    ->required(),
            ]);
    }

    public function read(LibraryImporter $importer): void
    {
        $state = $this->form->getState();
        $file = $state['file'] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $import = $importer->read($this->record, $file->getRealPath(), $file->getClientOriginalName(), auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } finally {
            $file->delete();
        }

        $counts = $import->counts();
        $this->form->fill();
        $this->resetTable();

        Notification::make()
            ->title("Read {$counts['total']} ".Str::plural('book', $counts['total']))
            ->body($counts['pending'] > 0
                ? "{$counts['accepted']} matched. {$counts['pending']} need you to check them."
                : 'Every one matched. Apply them to your list when ready.')
            ->success()
            ->send();
    }

    public function downloadTemplateAction(): Actions\Action
    {
        return Actions\Action::make('downloadTemplate')
            ->label('Download the template')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->action(function (LibraryImporter $importer): BinaryFileResponse {
                $path = tempnam(sys_get_temp_dir(), 'library-template').'.xlsx';
                $importer->template($path);

                return response()->download($path, 'kitoblar-shablon.xlsx')->deleteFileAfterSend();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('apply')
                ->label('Apply to my list')
                ->icon('heroicon-m-check')
                ->visible(fn () => $this->pendingImport() !== null)
                ->requiresConfirmation()
                ->modalHeading('Apply the accepted books to your list?')
                ->modalDescription(function (): string {
                    $counts = $this->pendingImport()?->counts() ?? ['accepted' => 0, 'pending' => 0];

                    return "{$counts['accepted']} ".Str::plural('line', $counts['accepted']).' will be added or updated.'
                        .($counts['pending'] > 0 ? " {$counts['pending']} not yet decided will be left out." : '');
                })
                ->form([
                    Forms\Components\Toggle::make('remove_missing')
                        ->label('Also remove books that are not in this file')
                        ->helperText('Use this when the file is your whole collection.'),
                ])
                ->action(function (array $data, LibraryImporter $importer) {
                    $import = $this->pendingImport();

                    if ($import === null) {
                        return;
                    }

                    $result = $importer->apply($import, (bool) ($data['remove_missing'] ?? false));

                    Notification::make()
                        ->title('Your list is updated')
                        ->body("{$result['added']} added, {$result['updated']} updated"
                            .($result['removed'] > 0 ? ", {$result['removed']} removed." : '.'))
                        ->success()
                        ->send();

                    $this->redirect(LibraryResource::getUrl('edit', ['record' => $this->record]));
                }),
            Actions\Action::make('discard')
                ->label('Start over')
                ->color('gray')
                ->visible(fn () => $this->pendingImport() !== null)
                ->requiresConfirmation()
                ->modalDescription('Drops this file and what you decided on it. Your list stays as it is.')
                ->action(function () {
                    $this->pendingImport()?->delete();
                    $this->resetTable();
                }),
            Actions\Action::make('back')
                ->label('Back to my books')
                ->color('gray')
                ->url(fn () => LibraryResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => LibraryImportRow::query()
                ->where('library_import_id', $this->pendingImport()?->id ?? 0)
                ->with('book.authors'))
            ->defaultSort('row_number')
            ->columns([
                Tables\Columns\TextColumn::make('row_number')->label('Row')->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('In your file')
                    ->description(fn (LibraryImportRow $row) => collect([$row->author, $row->isbn ? "ISBN {$row->isbn}" : null])->filter()->implode(' · '))
                    ->placeholder('—')
                    ->searchable(['title', 'author', 'isbn'])
                    ->wrap(),
                Tables\Columns\TextColumn::make('copies')
                    ->label('Copies')
                    ->formatStateUsing(fn (LibraryImportRow $row) => ($row->copies ?? 1).($row->available !== null ? " ({$row->available} on shelf)" : ''))
                    ->default('1'),
                Tables\Columns\TextColumn::make('book_id')
                    ->label('Book on kitoblarim.uz')
                    ->formatStateUsing(fn (LibraryImportRow $row) => $row->book ? BookPicker::label($row->book) : null)
                    ->description(fn (LibraryImportRow $row) => self::matchLabel($row))
                    ->placeholder('Not found')
                    ->wrap(),
                Tables\Columns\TextColumn::make('decision')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        LibraryImportRow::ACCEPT => 'Will be added',
                        LibraryImportRow::SKIP => 'Skipped',
                        default => 'Check this',
                    })
                    ->color(fn (string $state) => match ($state) {
                        LibraryImportRow::ACCEPT => 'success',
                        LibraryImportRow::SKIP => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('decision')
                    ->label('Show')
                    ->options([
                        LibraryImportRow::PENDING => 'To check',
                        LibraryImportRow::ACCEPT => 'Will be added',
                        LibraryImportRow::SKIP => 'Skipped',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn (LibraryImportRow $row) => $row->decision !== LibraryImportRow::ACCEPT && $row->book_id !== null)
                    ->action(fn (LibraryImportRow $row) => $row->update(['decision' => LibraryImportRow::ACCEPT])),
                Tables\Actions\Action::make('choose')
                    ->label(fn (LibraryImportRow $row) => $row->book_id ? 'Change' : 'Find the book')
                    ->icon('heroicon-m-magnifying-glass')
                    ->color('gray')
                    ->modalHeading(fn (LibraryImportRow $row) => $row->title ?? 'Which book is it?')
                    ->modalDescription(fn (LibraryImportRow $row) => $row->author)
                    ->form(fn (LibraryImportRow $row) => [
                        Forms\Components\Radio::make('candidate')
                            ->label('Did you mean')
                            ->options(Book::with('authors')->findMany($row->candidate_ids ?? [])
                                ->mapWithKeys(fn (Book $book) => [$book->id => BookPicker::label($book)])
                                ->all())
                            ->default($row->book_id)
                            ->visible(filled($row->candidate_ids)),
                        Forms\Components\Select::make('other')
                            ->label(filled($row->candidate_ids) ? 'Or another book' : 'Book')
                            ->helperText('Search by title, author or ISBN.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => BookPicker::search($search))
                            ->getOptionLabelUsing(fn ($value): ?string => BookPicker::labelFor($value)),
                    ])
                    ->action(function (LibraryImportRow $row, array $data) {
                        $bookId = $data['other'] ?? $data['candidate'] ?? null;

                        if ($bookId === null) {
                            return;
                        }

                        $row->update([
                            'book_id' => $bookId,
                            'edition_id' => null,
                            'match' => filled($data['other'] ?? null) ? LibraryImportRow::MATCH_MANUAL : $row->match,
                            'decision' => LibraryImportRow::ACCEPT,
                        ]);
                    }),
                Tables\Actions\Action::make('skip')
                    ->label('Skip')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->visible(fn (LibraryImportRow $row) => $row->decision !== LibraryImportRow::SKIP)
                    ->action(fn (LibraryImportRow $row) => $row->update(['decision' => LibraryImportRow::SKIP])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('acceptAll')
                    ->label('Accept')
                    ->icon('heroicon-m-check')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $rows) => LibraryImportRow::query()
                        ->whereKey($rows->modelKeys())
                        ->whereNotNull('book_id')
                        ->update(['decision' => LibraryImportRow::ACCEPT])),
                Tables\Actions\BulkAction::make('skipAll')
                    ->label('Skip')
                    ->icon('heroicon-m-x-mark')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $rows) => LibraryImportRow::query()
                        ->whereKey($rows->modelKeys())
                        ->update(['decision' => LibraryImportRow::SKIP])),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Upload a file above.');
    }

    private static function matchLabel(LibraryImportRow $row): ?string
    {
        return match ($row->match) {
            LibraryImportRow::MATCH_ISBN => 'Found by ISBN',
            LibraryImportRow::MATCH_EXACT => 'Same title and author',
            LibraryImportRow::MATCH_SUGGESTED => count($row->candidate_ids ?? []) > 1
                ? 'Best guess of '.count($row->candidate_ids).'. Check it'
                : 'Best guess. Check it',
            LibraryImportRow::MATCH_MANUAL => 'Chosen by you',
            default => null,
        };
    }
}
