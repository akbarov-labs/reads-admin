<?php

namespace App\Filament\Resources\BookResource\RelationManagers;

use App\Filament\Resources\TranslatorResource;
use App\Filament\Support\CatalogueFields;
use App\Models\Edition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every edition of the book, in full: credits, print details, gallery, excerpt. */
class EditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'editions';

    protected static ?string $modelLabel = 'edition';

    public function form(Form $form): Form
    {
        return $form->schema([
            CatalogueFields::publisherSelect(),
            CatalogueFields::statusSelect(),
            CatalogueFields::creditsRepeater(),
            ...CatalogueFields::editionFields(),
            Forms\Components\Repeater::make('images')
                ->label('Gallery')
                ->relationship()
                ->orderColumn('position')
                ->defaultItems(0)
                ->simple(
                    Forms\Components\FileUpload::make('path')
                        ->image()
                        ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                        ->maxSize(4096)
                        ->disk('public')
                        ->directory('editions')
                        ->required(),
                )
                ->columnSpanFull()
                ->collapsed(),
            CatalogueFields::excerptSection(),
            Forms\Components\Section::make('Source')
                ->schema([
                    Forms\Components\TextInput::make('source_url')->url()->maxLength(255),
                    Forms\Components\TextInput::make('source_article')->maxLength(32),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Edition $record) => $record->display_title)
            ->modifyQueryUsing(fn ($query) => $query->with(['publisher', 'translators']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')->label('')->disk('public'),
                Tables\Columns\TextColumn::make('publisher.name')->label('Publisher')->placeholder('—'),
                Tables\Columns\TextColumn::make('translators.name')->label('Translators')->listWithLineBreaks()->placeholder('—'),
                Tables\Columns\TextColumn::make('isbn')->label('ISBN')->searchable(),
                Tables\Columns\TextColumn::make('language')->toggleable(),
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
