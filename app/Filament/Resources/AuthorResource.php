<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuthorResource\Pages;
use App\Models\Author;
use App\Services\AuthorMerger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuthorResource extends Resource
{
    protected static ?string $model = Author::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Book authors';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Author identity')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('original_name')
                        ->label('Name in original script')
                        ->helperText('e.g. "Абдулла Қодирий" — lets editions in other languages find this author.')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('website_url')
                        ->url()
                        ->maxLength(255),
                    Forms\Components\FileUpload::make('portrait_path')
                        ->label('Portrait')
                        ->image()
                        ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                        ->maxSize(4096)
                        ->disk('public')
                        ->directory('authors'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Biography')
                ->schema([
                    Forms\Components\Tabs::make('Locales')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')
                                ->schema(self::localizedFields('uz')),
                            Forms\Components\Tabs\Tab::make('Russian')
                                ->schema(self::localizedFields('ru')),
                            Forms\Components\Tabs\Tab::make('English')
                                ->schema(self::localizedFields('en')),
                        ])
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Life dates')
                ->schema([
                    Forms\Components\TextInput::make('birth_year')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(9999),
                    Forms\Components\TextInput::make('death_year')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(9999),
                ])
                ->columns(2),
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    private static function localizedFields(string $locale): array
    {
        return [
            Forms\Components\Textarea::make("bio.{$locale}")
                ->label('Biography')
                ->rows(4),
            Forms\Components\TextInput::make("nationality.{$locale}")
                ->label('Nationality'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('portrait_path')
                    ->label('')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('books_count')
                    ->counts('books')
                    ->label('Books')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::mergeAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The same person entered twice ("Toxir Malik" beside "Tohir Malik"):
     * this row's books and whatever the other lacks move to the author
     * picked, and this one is deleted. Its name and link keep leading there
     * (AuthorMerger). `php artisan authors:duplicates` lists likely pairs.
     */
    private static function mergeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('merge')
            ->label('Merge into…')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading(fn (Author $record): string => "Merge “{$record->name}” into another author")
            ->modalDescription('Its books, and any details the kept author has left blank, move to the author you pick. This row is then deleted; its name and its page link keep leading to the kept author. This cannot be undone.')
            ->modalSubmitActionLabel('Merge')
            ->form([
                Forms\Components\Select::make('into')
                    ->label('Keep this author')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => Author::withCount('books')
                        ->where(fn (Builder $match) => $match->where('name', 'like', "%{$search}%")->orWhere('original_name', 'like', "%{$search}%"))
                        ->orderByDesc('books_count')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Author $author) => [$author->id => "{$author->name} ({$author->books_count} books, /{$author->slug})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Author::find($value)?->name),
            ])
            ->action(function (Author $record, array $data): void {
                $into = Author::find($data['into']);

                if ($into === null || $into->is($record)) {
                    Notification::make()->danger()->title('Pick another author to keep.')->send();

                    return;
                }

                $name = $record->name;
                app(AuthorMerger::class)->merge($record, $into);

                Notification::make()->success()->title("“{$name}” is now part of “{$into->name}”.")->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('books');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuthors::route('/'),
            'create' => Pages\CreateAuthor::route('/create'),
            'edit' => Pages\EditAuthor::route('/{record}/edit'),
        ];
    }
}
