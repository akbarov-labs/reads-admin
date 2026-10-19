<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Book;
use App\Models\Edition;
use App\Models\Quote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Lines from books for the site: each book's page shows its quotes, and the
 * home page one a day (Quote::ofTheDay). A quote is published when saved;
 * "Hidden" takes it off the site without losing it.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = 'Quotes';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('book_id')
                ->label('Book')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Book::query()
                    ->where('uzbek_title', 'like', "%{$search}%")
                    ->orWhere('original_title', 'like', "%{$search}%")
                    ->orderBy('uzbek_title')
                    ->limit(50)
                    ->pluck('uzbek_title', 'id')
                    ->all())
                ->getOptionLabelUsing(fn ($value): ?string => Book::find($value)?->uzbek_title)
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('edition_id', null)),
            Forms\Components\Select::make('edition_id')
                ->label('Edition (whose translation)')
                ->helperText('For a translated book, the edition the line is copied from: its translators are named with the quote.')
                ->options(fn (Get $get): array => Edition::query()
                    ->where('book_id', $get('book_id'))
                    ->with(['publisher', 'translators'])
                    ->get()
                    ->mapWithKeys(fn (Edition $edition) => [$edition->id => collect([
                        $edition->publisher?->name,
                        $edition->year,
                        $edition->translators->pluck('name')->implode(', '),
                    ])->filter()->implode(' · ') ?: "ISBN {$edition->isbn}"])
                    ->all())
                ->visible(fn (Get $get): bool => filled($get('book_id'))),
            Forms\Components\Textarea::make('body')
                ->label('Quote')
                ->required()
                ->rows(4)
                ->maxLength(2000)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('page')
                ->numeric()
                ->minValue(1)
                ->maxValue(65535),
            Forms\Components\Select::make('status')
                ->options([
                    ModerationStatus::Approved->value => 'Published',
                    ModerationStatus::Rejected->value => 'Hidden',
                ])
                ->default(ModerationStatus::Approved->value)
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('body')
                    ->label('Quote')
                    ->limit(100)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Book')
                    ->searchable(),
                Tables\Columns\TextColumn::make('page')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
