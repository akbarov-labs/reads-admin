<?php

namespace App\Filament\Resources\LibraryResource\RelationManagers;

use App\Filament\Support\BookPicker;
use App\Models\Edition;
use App\Models\LibraryHolding;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * The books a library holds. "Lent out" and "Returned" are one click each
 * from the list, since they happen at the desk while a reader waits; the
 * count is all that is kept, never who has the book.
 */
class HoldingsRelationManager extends RelationManager
{
    protected static string $relationship = 'holdings';

    protected static ?string $title = 'Books';

    protected static ?string $icon = 'heroicon-m-book-open';

    // The tab the page opens on, so it renders with the page rather than
    // popping in a moment later.
    protected static bool $isLazy = false;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->holdings()->count();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('book_id')
                ->label('Book')
                ->helperText('Search by title, author or ISBN.')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => BookPicker::search($search))
                ->getOptionLabelUsing(fn ($value): ?string => BookPicker::labelFor($value))
                ->unique(
                    table: 'library_holdings',
                    column: 'book_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('library_id', $this->getOwnerRecord()->getKey()),
                )
                ->validationMessages(['unique' => 'This book is already on the list. Change its copies instead.'])
                ->disabledOn('edit')
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('edition_id', null))
                ->columnSpanFull(),
            Forms\Components\Select::make('edition_id')
                ->label('Which printing')
                ->helperText('Optional. Readers see the publisher when it is set.')
                ->options(fn (Get $get): array => Edition::query()
                    ->where('book_id', $get('book_id'))
                    ->approved()
                    ->with(['publisher', 'translators'])
                    ->get()
                    ->mapWithKeys(fn (Edition $edition) => [$edition->id => collect([
                        $edition->publisher?->name,
                        $edition->year,
                        $edition->translators->pluck('name')->implode(', '),
                    ])->filter()->implode(' · ') ?: "ISBN {$edition->isbn}"])
                    ->all())
                ->visible(fn (Get $get): bool => filled($get('book_id')))
                ->columnSpanFull(),
            Forms\Components\TextInput::make('copies_total')
                ->label('Copies')
                ->numeric()
                ->minValue(1)
                ->maxValue(999)
                ->default(1)
                ->required()
                ->live(onBlur: true),
            Forms\Components\TextInput::make('copies_available')
                ->label('On the shelf now')
                ->numeric()
                ->minValue(0)
                ->maxValue(fn (Get $get) => max(1, (int) $get('copies_total')))
                ->default(1)
                ->required(),
            Forms\Components\TextInput::make('note')
                ->label('Note for yourself')
                ->helperText('A shelf code or the like. Not shown on the site.')
                ->maxLength(255)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['book.authors', 'edition.publisher']))
            ->recordTitle(fn (LibraryHolding $holding) => $holding->book?->uzbek_title)
            ->columns([
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Book')
                    ->description(fn (LibraryHolding $holding) => collect([
                        $holding->book?->author_names,
                        $holding->edition?->publisher?->name,
                    ])->filter()->implode(' · '))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('copies_available')
                    ->label('On the shelf')
                    ->formatStateUsing(fn (LibraryHolding $holding) => "{$holding->copies_available} / {$holding->copies_total}")
                    ->badge()
                    ->color(fn (LibraryHolding $holding) => $holding->copies_available > 0 ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('book.uzbek_title')
            ->filters([
                Tables\Filters\Filter::make('lent_out')
                    ->label('All copies lent out')
                    ->query(fn (Builder $query) => $query->where('copies_available', 0)),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add a book')
                    ->createAnother(true),
            ])
            ->actions([
                Tables\Actions\Action::make('lend')
                    ->label('Lent out')
                    ->icon('heroicon-m-arrow-up-right')
                    ->color('warning')
                    ->visible(fn (LibraryHolding $holding) => $holding->copies_available > 0)
                    ->action(fn (LibraryHolding $holding) => $holding->lend()),
                Tables\Actions\Action::make('giveBack')
                    ->label('Returned')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (LibraryHolding $holding) => $holding->copies_available < $holding->copies_total)
                    ->action(fn (LibraryHolding $holding) => $holding->giveBack()),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()->label('Remove from the list'),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('allBack')
                        ->label('All copies back on the shelf')
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $holdings) => $holdings->each(
                            fn (LibraryHolding $holding) => $holding->update(['copies_available' => $holding->copies_total]),
                        )),
                    Tables\Actions\DeleteBulkAction::make()->label('Remove from the list'),
                ]),
            ])
            ->emptyStateHeading('No books yet')
            ->emptyStateDescription('Add them one by one, or import your list from Excel at the top of the page.');
    }
}
