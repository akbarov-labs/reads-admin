<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreListingResource\Pages;
use App\Models\StoreListing;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * What the online stores sell, as stores:qamar last read it, for an admin
 * to act on:
 *
 *  - "New to us": books on sale whose ISBN the catalogue doesn't have.
 *  - "Differences": books where the store and the catalogue disagree on the
 *    publisher, the author or the page count. Nothing was changed; the
 *    edition is one click away to correct, if the store is right.
 *  - "Filled": books that got a cover or a page count from the store.
 *
 * Read-only: the listings are the stores' word, not the catalogue's.
 */
class StoreListingResource extends Resource
{
    protected static ?string $model = StoreListing::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Store listings';

    protected static ?string $modelLabel = 'store listing';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 7;

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('edition.book');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('In the store')
                    ->description(fn (StoreListing $row) => collect([$row->author, $row->publisher])->filter()->implode(' · '))
                    ->searchable(['title', 'author', 'publisher', 'isbn'])
                    ->sortable()
                    ->url(fn (StoreListing $row) => $row->url, shouldOpenInNewTab: true)
                    ->wrap(),
                Tables\Columns\TextColumn::make('isbn')
                    ->label('ISBN')
                    ->placeholder('None')
                    ->copyable(),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? Number::format($state).' soʻm' : null)
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('edition_id')
                    ->label('Book on kitoblarim.uz')
                    ->formatStateUsing(fn (StoreListing $row) => $row->edition?->book?->uzbek_title)
                    ->url(fn (StoreListing $row) => $row->edition?->book_id ? BookResource::getUrl('edit', ['record' => $row->edition->book_id]) : null)
                    ->placeholder('Not in the catalogue')
                    ->wrap(),
                Tables\Columns\TextColumn::make('differences')
                    ->label('Store says otherwise')
                    ->getStateUsing(fn (StoreListing $row) => self::differencesText($row))
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('filled')
                    ->label('Filled from it')
                    ->getStateUsing(fn (StoreListing $row) => $row->filled ? implode(', ', $row->filled) : null)
                    ->placeholder('—'),
            ]);
    }

    /** "publisher: Sharq → Bio-factor press; pages: 320 → 544" */
    public static function differencesText(StoreListing $row): ?string
    {
        if (blank($row->differences)) {
            return null;
        }

        return collect($row->differences)
            ->map(fn (array $pair, string $field) => sprintf('%s: %s → %s', $field, $pair[0] ?? 'none', $pair[1] ?? 'none'))
            ->implode('; ');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoreListings::route('/'),
        ];
    }
}
