<?php

namespace App\Filament\Resources\PublisherResource\RelationManagers;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Filament\Resources\TranslatorResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The books a publisher has published.
 *
 * These go live immediately rather than into the moderation queue. A
 * publisher describing their own catalogue is the primary source for it —
 * unlike a taqrizchi, who is adding somebody else's book in order to review
 * it, and whose entries an admin checks first.
 *
 * The book rows here are editions, not works: two publishers issuing the same
 * public-domain title each own their own row, with their own cover, year and
 * print quality. That is deliberate — see the Taqriz aspect for cover and
 * print quality, which is meaningless if editions share a row.
 */
class BooksRelationManager extends RelationManager
{
    protected static string $relationship = 'books';

    protected static ?string $title = 'Books we publish';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('uzbek_title')
                ->label('Title')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('original_title')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('author')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('source_language')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('translator_id')
                ->label('Translator')
                ->relationship('translator', 'name')
                ->searchable()
                ->preload()
                // Optional: an original-language work has no translator, and
                // a translation whose translator has no profile here still
                // belongs in the catalogue.
                ->helperText('Leave blank for an original-language work, or if the translator has no profile here.'),

            Forms\Components\TextInput::make('year')
                ->numeric()
                ->minValue(1000)
                ->maxValue(9999),

            Forms\Components\Select::make('role')
                ->options(BookRole::class)
                ->required()
                ->default(BookRole::Translator),

            Forms\Components\FileUpload::make('cover_path')
                ->label('Cover image')
                ->image()
                ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                ->maxSize(4096)
                ->disk('public')
                ->directory('covers')
                ->imageEditor()
                ->imageEditorAspectRatios(['2:3'])
                ->columnSpanFull(),

            Forms\Components\Select::make('status')
                ->options(ModerationStatus::class)
                ->required()
                ->default(ModerationStatus::Approved)
                ->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uzbek_title')
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')->label('')->disk('public'),
                Tables\Columns\TextColumn::make('uzbek_title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->author),
                Tables\Columns\TextColumn::make('translator.name')
                    ->label('Translator')
                    ->placeholder('—'),
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
