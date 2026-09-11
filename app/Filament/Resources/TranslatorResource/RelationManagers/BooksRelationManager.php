<?php

namespace App\Filament\Resources\TranslatorResource\RelationManagers;

use App\Enums\BookRole;
use App\Filament\Resources\TranslatorResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BooksRelationManager extends RelationManager
{
    protected static string $relationship = 'books';

    protected static ?string $title = 'Books & translations';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('role')
                ->options(BookRole::class)
                ->required()
                ->default(BookRole::Translator),
            Forms\Components\TextInput::make('uzbek_title')
                ->label('Title (in your language)')
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
            Forms\Components\TextInput::make('publisher')
                ->maxLength(255),
            Forms\Components\TextInput::make('year')
                ->numeric()
                ->minValue(1000)
                ->maxValue(9999),
            Forms\Components\FileUpload::make('cover_path')
                ->label('Cover image')
                ->image()
                // @see TranslatorResource::SAFE_IMAGE_TYPES — no SVG.
                ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                ->maxSize(4096)
                ->disk('public')
                ->directory('covers')
                ->imageEditor()
                ->imageEditorAspectRatios(['2:3'])
                ->columnSpanFull(),

            Forms\Components\Section::make('Side-by-side excerpt (optional)')
                ->description('Shown in the "Compare the text" section of the public profile.')
                ->schema([
                    Forms\Components\TextInput::make('excerpt_source_language')
                        ->label('Source language label'),
                    Forms\Components\Textarea::make('excerpt_source_text')
                        ->label('Original text')
                        ->rows(4),
                    Forms\Components\Textarea::make('excerpt_translated_text')
                        ->label('Translated text')
                        ->rows(4),
                ])
                ->columns(1)
                ->columnSpanFull()
                ->collapsible(),

            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uzbek_title')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')
                    ->label('')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('uzbek_title')
                    ->label('Title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('original_title')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('author')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
