<?php

namespace App\Filament\Resources\TranslatorResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LanguagePairsRelationManager extends RelationManager
{
    protected static string $relationship = 'languagePairs';

    protected static ?string $title = 'Language pairs';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('from_language')
                ->label('From')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('to_language')
                ->label('To')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('from_language')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('from_language')->label('From'),
                Tables\Columns\TextColumn::make('to_language')->label('To'),
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
