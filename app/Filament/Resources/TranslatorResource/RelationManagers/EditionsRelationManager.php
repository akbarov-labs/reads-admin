<?php

namespace App\Filament\Resources\TranslatorResource\RelationManagers;

use App\Enums\BookRole;
use App\Filament\Support\CatalogueFields;
use App\Models\Edition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A translator's portfolio: the editions they worked on, each with their
 * role on it. Two translations of one book are two rows here, each its own
 * edition with its own publisher, cover and excerpt; the book they share is
 * picked (or added) at the top of the form.
 *
 * Removing a row detaches the translator from the edition rather than
 * deleting it — a co-translator's or a publisher's claim on the same
 * edition is not this translator's to erase. An admin can delete outright.
 */
class EditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'editions';

    protected static ?string $title = 'Books & translations';

    protected static ?string $modelLabel = 'edition';

    public function form(Form $form): Form
    {
        return $form->schema([
            CatalogueFields::bookSelect()->columnSpanFull(),

            // Stored on the pivot: this translator's credit on the edition.
            Forms\Components\Select::make('role')
                ->label('Your role')
                ->options(BookRole::class)
                ->required()
                ->default(BookRole::Translator),

            CatalogueFields::publisherSelect(),

            ...CatalogueFields::editionFields(),

            CatalogueFields::excerptSection(),

            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Edition $record) => $record->display_title)
            ->modifyQueryUsing(fn ($query) => $query->with(['book.authors', 'publisher']))
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')
                    ->label('')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Title')
                    ->description(fn (Edition $record) => $record->book?->author_names)
                    ->searchable(),
                Tables\Columns\TextColumn::make('publisher.name')
                    ->label('Publisher')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\AttachAction::make()
                    ->label('Add an existing edition')
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                    ->recordSelectSearchColumns(['title', 'isbn'])
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('role')->options(BookRole::class)->default(BookRole::Translator)->required(),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DetachAction::make()
                    ->label('Remove'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false),
            ]);
    }
}
