<?php

namespace App\Filament\Resources;

use App\Enums\BookProblem;
use App\Enums\ModerationStatus;
use App\Filament\Resources\BookResource\Pages;
use App\Filament\Resources\BookResource\RelationManagers\EditionsRelationManager;
use App\Filament\Support\PendingNavigationBadge;
use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catalogue of works, for admins: what each book is (titles, authors,
 * genres, series) and, underneath, every edition of it. Translators and
 * publishers reach the same books through their own editions; this is the
 * one place that sees a book whole, and where the moderation queue for
 * books added by taqrizchilar is worked through.
 */
class BookResource extends Resource
{
    use PendingNavigationBadge;

    protected static ?string $model = Book::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Books';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Book::where('status', ModerationStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The book')
                ->description('What every edition of it has in common. Publisher, ISBN, translators and cover belong to each edition, below.')
                ->schema([
                    Forms\Components\TextInput::make('uzbek_title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('original_title')
                        ->label('Original title')
                        ->maxLength(255),
                    Forms\Components\Select::make('authors')
                        ->relationship('authors', 'name')
                        ->multiple()
                        ->searchable()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required()->maxLength(255),
                            Forms\Components\TextInput::make('original_name')->label('Name in original script')->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): int => Author::create([
                            ...$data,
                            'slug' => Author::generateUniqueSlug($data['name']),
                        ])->getKey()),
                    Forms\Components\TextInput::make('source_language')
                        ->label('Original language')
                        ->maxLength(255),
                    Forms\Components\Textarea::make('description')
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Shelves')
                ->schema([
                    Forms\Components\Select::make('categories')
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('The genre and every other shelf the book belongs on.'),
                    Forms\Components\Select::make('series_id')
                        ->label('Series')
                        ->relationship('series', 'name')
                        ->searchable()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): int => Series::create([
                            'name' => $data['name'],
                            'slug' => Series::generateUniqueSlug($data['name']),
                        ])->getKey()),
                    Forms\Components\TextInput::make('series_position')
                        ->label('Position in series')
                        ->numeric()
                        ->step(0.5),
                ])
                ->columns(3),

            Forms\Components\Section::make('Moderation')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(ModerationStatus::class)
                        ->required()
                        ->default(ModerationStatus::Approved),
                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['authors'])->withCount('editions'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('uzbek_title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Book $record) => $record->author_names)
                    ->wrap(),
                Tables\Columns\TextColumn::make('editions_count')
                    ->label('Editions')
                    ->sortable(),
                Tables\Columns\TextColumn::make('categories.name')
                    ->label('Shelves')
                    ->badge()
                    ->limitList(2)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('series.name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(ModerationStatus::class),
                Tables\Filters\SelectFilter::make('categories')->relationship('categories', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('authors')->relationship('authors', 'name')->searchable(),
                // The same checks as `php artisan books:quality`.
                Tables\Filters\SelectFilter::make('needs_attention')
                    ->label('Needs attention')
                    ->options(['any' => 'Any problem', ...collect(BookProblem::cases())
                        ->mapWithKeys(fn (BookProblem $problem) => [$problem->value => $problem->getLabel()])->all()])
                    ->query(function (Builder $query, array $data): void {
                        if (blank($data['value'] ?? null)) {
                            return;
                        }

                        $data['value'] === 'any'
                            ? BookProblem::applyAny($query)
                            : BookProblem::from($data['value'])->apply($query);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => ModerationStatus::Approved])),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [EditionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBooks::route('/'),
            'create' => Pages\CreateBook::route('/create'),
            'edit' => Pages\EditBook::route('/{record}/edit'),
        ];
    }
}
