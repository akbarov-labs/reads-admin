<?php

namespace App\Filament\Resources\PublisherResource\RelationManagers;

use App\Filament\Support\CatalogueFields;
use App\Models\Edition;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The editions a publisher has printed.
 *
 * These go live immediately rather than into the moderation queue. A
 * publisher describing their own catalogue is the primary source for it —
 * unlike a taqrizchi, who is adding somebody else's book in order to review
 * it, and whose entries an admin checks first.
 *
 * Two publishers issuing the same title each have their own edition of one
 * shared book, with their own cover, year and print quality — which is what
 * the taqriz aspect for cover and print quality is scoring.
 */
class EditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'editions';

    protected static ?string $title = 'Books we publish';

    protected static ?string $modelLabel = 'edition';

    public function form(Form $form): Form
    {
        return $form->schema([
            CatalogueFields::bookSelect()->columnSpanFull(),
            CatalogueFields::creditsRepeater()
                ->helperText('Leave empty for an original-language work. Not in the list? Add their name — an admin can turn it into a full profile with a login later.'),
            ...CatalogueFields::editionFields(),
            CatalogueFields::statusSelect(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Edition $record) => $record->display_title)
            ->modifyQueryUsing(fn ($query) => $query->with(['book.authors', 'translators']))
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')->label('')->disk('public'),
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Edition $record) => $record->book?->author_names),
                Tables\Columns\TextColumn::make('translators.name')
                    ->label('Translators')
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('isbn')->label('ISBN')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('year')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
