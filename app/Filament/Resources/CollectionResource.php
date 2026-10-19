<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CollectionResource\Pages;
use App\Models\Book;
use App\Models\Collection;
use App\Models\Edition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Editors' collections for the site ("toʻplamlar"): a title and a few lines
 * in each language, then the books in order — each optionally the one
 * translation the list means, with a line on why it is there. Off the site
 * until "Published" is on.
 */
class CollectionResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Collections';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Collection')
                ->schema([
                    Forms\Components\Tabs::make('Locales')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')->schema(self::localizedFields('uz', required: true)),
                            Forms\Components\Tabs\Tab::make('Russian')->schema(self::localizedFields('ru')),
                            Forms\Components\Tabs\Tab::make('English')->schema(self::localizedFields('en')),
                        ])
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')
                        ->helperText('Its address on the site: /collection/<slug>. Made from the Uzbek title when left empty.')
                        ->alphaDash()
                        ->required(fn (string $operation): bool => $operation === 'edit')
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('position')
                        ->helperText('Lower comes first in the list of collections.')
                        ->numeric()
                        ->default(0),
                    Forms\Components\Toggle::make('is_published')
                        ->label('Published')
                        ->helperText('Shown on the site.'),
                ])
                ->columns(3),

            Forms\Components\Repeater::make('items')
                ->label('Books')
                ->relationship()
                ->orderColumn('position')
                ->reorderableWithButtons()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => Book::find($state['book_id'] ?? null)?->uzbek_title)
                ->schema([
                    Forms\Components\Select::make('book_id')
                        ->label('Book')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Book::approved()
                            ->where(fn ($match) => $match->where('uzbek_title', 'like', "%{$search}%")->orWhere('original_title', 'like', "%{$search}%"))
                            ->orderBy('uzbek_title')
                            ->limit(50)
                            ->pluck('uzbek_title', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Book::find($value)?->uzbek_title)
                        ->distinct()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('edition_id', null)),
                    Forms\Components\Select::make('edition_id')
                        ->label('Translation (edition)')
                        ->helperText('When the list means one translation of the book.')
                        ->options(fn (Get $get): array => Edition::query()
                            ->where('book_id', $get('book_id'))
                            ->with(['publisher', 'translators'])
                            ->get()
                            ->mapWithKeys(fn (Edition $edition) => [$edition->id => collect([
                                $edition->translators->pluck('name')->implode(', '),
                                $edition->publisher?->name,
                                $edition->year,
                            ])->filter()->implode(' · ') ?: "ISBN {$edition->isbn}"])
                            ->all())
                        ->visible(fn (Get $get): bool => filled($get('book_id'))),
                    Forms\Components\Textarea::make('note')
                        ->label('Why it is here')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    private static function localizedFields(string $locale, bool $required = false): array
    {
        return [
            Forms\Components\TextInput::make("title.{$locale}")
                ->label('Title')
                ->required($required)
                ->maxLength(150),
            Forms\Components\Textarea::make("description.{$locale}")
                ->label('Description')
                ->rows(3)
                ->maxLength(1000),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Books'),
                Tables\Columns\IconColumn::make('is_published')->label('Published')->boolean(),
                Tables\Columns\TextColumn::make('position')->sortable(),
            ])
            ->defaultSort('position')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollections::route('/'),
            'create' => Pages\CreateCollection::route('/create'),
            'edit' => Pages\EditCollection::route('/{record}/edit'),
        ];
    }
}
