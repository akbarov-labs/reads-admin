<?php

namespace App\Filament\Resources\TranslatorResource\RelationManagers;

use App\Enums\SocialPlatform;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SocialLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'socialLinks';

    protected static ?string $title = 'Social links';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('platform')
                ->options(SocialPlatform::class)
                ->required()
                // One row per platform — the unique DB constraint would
                // reject a duplicate anyway, but this stops the mistake
                // before a failed save.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('translator_id', $this->getOwnerRecord()->id)),
            Forms\Components\TextInput::make('url')
                ->label('Profile URL')
                ->url()
                ->required()
                ->placeholder('https://...')
                ->maxLength(255),
            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->badge()
                    ->icon(fn (SocialPlatform $state) => $state->getIcon()),
                Tables\Columns\TextColumn::make('url')
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab()
                    ->limit(40),
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
