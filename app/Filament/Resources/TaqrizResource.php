<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Enums\TaqrizStatus;
use App\Filament\Resources\TaqrizResource\Pages;
use App\Models\Book;
use App\Models\Taqriz;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaqrizResource extends Resource
{
    protected static ?string $model = Taqriz::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Taqrizlar';

    /**
     * Filament derives the URL from the class name, which gives "taqrizs" —
     * English pluralisation applied to an Uzbek word. Pinned to the real
     * plural so the panel URL matches the public site's /taqriz routes.
     */
    protected static ?string $slug = 'taqrizlar';

    protected static ?string $modelLabel = 'taqriz';

    protected static ?string $pluralModelLabel = 'taqrizlar';

    /**
     * Admins need the queue; reviewers need their own reviews. Everyone with
     * panel access who is one of those two gets the nav entry.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || $user?->isTaqrizchi());
    }

    /** Admins see the whole queue; a reviewer sees only what they wrote. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['book', 'taqrizchi']);
        $user = auth()->user();

        if ($user && ! $user->isAdmin()) {
            $query->where('taqrizchi_id', $user->taqrizchi?->id);
        }

        return $query;
    }

    /** The badge is the admin's to-do count, so it only counts pending. */
    public static function getNavigationBadge(): ?string
    {
        if (! (auth()->user()?->isAdmin() ?? false)) {
            return null;
        }

        $pending = Taqriz::query()->where('status', TaqrizStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('What is being reviewed')
                ->schema([
                    Forms\Components\Select::make('taqrizchi_id')
                        ->label('Taqrizchi')
                        ->relationship('taqrizchi', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        // A reviewer writes as themselves and cannot pick
                        // somebody else; an admin writes on their behalf.
                        ->default(fn () => auth()->user()?->taqrizchi?->id)
                        ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                        ->dehydrated(),

                    Forms\Components\Select::make('book_id')
                        ->label('Book')
                        ->relationship('book', 'uzbek_title')
                        ->getOptionLabelFromRecordUsing(fn (Book $record) => sprintf(
                            '%s — %s%s',
                            $record->uzbek_title,
                            $record->author,
                            $record->year ? " ({$record->year})" : '',
                        ))
                        ->searchable(['uzbek_title', 'original_title', 'author'])
                        ->preload()
                        ->required()
                        // The translation-quality aspect appears or
                        // disappears with the chosen book, so the form has to
                        // know about the change as it happens.
                        ->live()
                        ->helperText('Not in the list? Add it — it will be published once an admin approves it.')
                        ->createOptionForm(self::bookFields())
                        ->createOptionUsing(function (array $data): int {
                            // Explicitly pending: the table defaults to
                            // approved so that translator-entered books stay
                            // live, so this path opts itself into the queue.
                            $data['status'] = ModerationStatus::Pending;

                            return Book::create($data)->getKey();
                        }),
                ])
                ->columns(2),

            Forms\Components\Section::make('The taqriz')
                ->schema([
                    Forms\Components\Textarea::make('body')
                        ->label('Review')
                        ->required()
                        ->rows(12)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('youtube_url')
                        ->label('YouTube video (optional)')
                        // Bare `url` accepts any scheme, which would put a
                        // javascript: URL into an href on the public site.
                        ->rules(['url:http,https'])
                        ->placeholder('https://youtube.com/watch?v=...')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Score (optional)')
                ->description('Rate only what you want to. The overall figure is the average of whichever aspects you fill in.')
                ->schema(self::aspectFields())
                ->columns(2),

            Forms\Components\Section::make('Moderation')
                ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(TaqrizStatus::class)
                        ->required()
                        ->default(TaqrizStatus::Pending)
                        ->helperText('Only admins can publish. TaqrizObserver enforces this regardless of what this form sends.'),
                ]),
        ]);
    }

    /**
     * The five aspects, built from the model's own list so the form cannot
     * drift out of step with the columns or the average.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function aspectFields(): array
    {
        $options = [];

        // 1.0 to 10.0 in half steps, as discrete choices rather than a free
        // number field — it rules out 11, -3 and 7.3 without any validation.
        for ($value = 2; $value <= 20; $value++) {
            $score = $value / 2;
            $options[(string) $score] = number_format($score, 1);
        }

        $fields = [];

        foreach (Taqriz::ASPECTS as $column => $label) {
            $field = Forms\Components\Select::make($column)
                ->label($label)
                ->options($options)
                ->native(false);

            if ($column === 'score_translation') {
                // A book nobody has translated has no translation to rate.
                // Hidden rather than disabled, and nulled on save, so an
                // answer cannot survive the book being changed underneath it.
                $field
                    ->visible(fn (Get $get) => filled($get('book_id'))
                        && Book::whereKey($get('book_id'))->whereNotNull('translator_id')->exists())
                    ->dehydrateStateUsing(fn ($state, Get $get) => filled($get('book_id'))
                        && Book::whereKey($get('book_id'))->whereNotNull('translator_id')->exists()
                            ? $state
                            : null);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Enough of a book to publish one. Deliberately omits translator: a
     * reviewer is describing a book, not claiming to have translated it.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function bookFields(): array
    {
        return [
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
            Forms\Components\TextInput::make('publisher')
                ->maxLength(255),
            Forms\Components\TextInput::make('year')
                ->numeric()
                ->minValue(1000)
                ->maxValue(9999),
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
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('book.cover_path')
                    ->label('')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Book')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Taqriz $record) => $record->book?->author),
                Tables\Columns\TextColumn::make('taqrizchi.name')
                    ->label('Taqrizchi')
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false),
                Tables\Columns\TextColumn::make('average_score')
                    ->label('Score')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 1).' / 10'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TaqrizStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Taqriz $record) => (auth()->user()?->isAdmin() ?? false)
                        && $record->status !== TaqrizStatus::Approved)
                    ->action(function (Taqriz $record): void {
                        $record->status = TaqrizStatus::Approved;
                        $record->save();

                        // A review can only be published against a book that
                        // is itself published, or it would appear on a page
                        // the site refuses to serve.
                        if ($record->book && $record->book->status !== ModerationStatus::Approved) {
                            $record->book->status = ModerationStatus::Approved;
                            $record->book->save();
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Taqriz $record) => (auth()->user()?->isAdmin() ?? false)
                        && $record->status !== TaqrizStatus::Rejected)
                    ->action(fn (Taqriz $record) => tap($record)->update(['status' => TaqrizStatus::Rejected])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaqrizlar::route('/'),
            'create' => Pages\CreateTaqriz::route('/create'),
            'edit' => Pages\EditTaqriz::route('/{record}/edit'),
        ];
    }
}
