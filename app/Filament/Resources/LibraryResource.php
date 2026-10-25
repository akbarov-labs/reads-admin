<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LibraryResource\Pages;
use App\Filament\Resources\LibraryResource\RelationManagers\HoldingsRelationManager;
use App\Models\Library;
use App\Support\Regions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Libraries (kutubxonalar). An admin adds one with its owner's login; the
 * owner keeps the page and the books from then on, scoped to their own row
 * like a publisher. Their sidebar is hidden (AdminPanelProvider), so they
 * reach this through LibraryProfileWidget.
 */
class LibraryResource extends Resource
{
    protected static ?string $model = Library::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Libraries';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getRelations(): array
    {
        return [HoldingsRelationManager::class];
    }

    public static function form(Form $form): Form
    {
        $isAdmin = fn (): bool => auth()->user()?->isAdmin() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Login account')
                ->description('Credentials the library uses to sign in. Only admins can see or change these.')
                ->visible($isAdmin)
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->helperText('Leave blank while the admin keeps this library.')
                        ->unique(
                            table: 'users',
                            column: 'email',
                            modifyRuleUsing: fn (Unique $rule, $record) => $record?->user_id
                                ? $rule->ignore($record->user_id)
                                : $rule,
                        ),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->label(fn (string $context) => $context === 'create' ? 'Password' : 'Reset password')
                        ->helperText(fn (string $context) => $context === 'create'
                            ? 'Share this with the library — it will not be shown again.'
                            : 'Leave blank to keep the current password.')
                        ->default(fn (string $context) => $context === 'create' ? Str::password(12) : null)
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columns(2),

            Forms\Components\Section::make('Library')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('The page address: kitoblarim.uz/library/<slug>.')
                    // LibraryObserver is what actually stops an owner moving it.
                    ->disabled(fn () => ! $isAdmin()),
                Forms\Components\FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                    ->maxSize(4096)
                    ->disk('public')
                    ->directory('libraries'),
                Forms\Components\Toggle::make('is_published')
                    ->label('On the site')
                    ->helperText('Turn on once the library has its books in.')
                    ->visible($isAdmin),
            ])->columns(2),

            Forms\Components\Section::make('Where')->schema([
                Forms\Components\Select::make('region')
                    ->options(Regions::options())
                    ->default(Regions::TASHKENT_CITY)
                    ->required()
                    ->live(),
                // Tashkent's districts are a fixed list the site filters on;
                // elsewhere a district is typed. Only the visible one saves.
                Forms\Components\Select::make('district')
                    ->options(Regions::tashkentDistrictOptions())
                    ->searchable()
                    ->visible(fn (Get $get) => $get('region') === Regions::TASHKENT_CITY),
                Forms\Components\TextInput::make('district')
                    ->label('District or city')
                    ->maxLength(255)
                    ->visible(fn (Get $get) => $get('region') !== Regions::TASHKENT_CITY),
                Forms\Components\Textarea::make('address')
                    ->rows(2)
                    ->helperText('As a reader would need it to find the door: street, building, landmark, floor.')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
                Forms\Components\TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
            ])->columns(2),

            Forms\Components\Section::make('Contacts')->schema([
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50),
                Forms\Components\TextInput::make('telegram')->prefix('t.me/')->maxLength(255),
                Forms\Components\TextInput::make('instagram')->prefix('instagram.com/')->maxLength(255),
                Forms\Components\TextInput::make('website_url')->label('Website')->url()->maxLength(255),
            ])->columns(2),

            Forms\Components\Section::make('About, hours and membership')->schema([
                Forms\Components\Tabs::make('Locales')->tabs([
                    Forms\Components\Tabs\Tab::make('Uzbek')->schema(self::localizedFields('uz')),
                    Forms\Components\Tabs\Tab::make('Russian')->schema(self::localizedFields('ru')),
                    Forms\Components\Tabs\Tab::make('English')->schema(self::localizedFields('en')),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    private static function localizedFields(string $locale): array
    {
        return [
            Forms\Components\Textarea::make("description.{$locale}")->label('About')->rows(3),
            Forms\Components\TextInput::make("hours.{$locale}")
                ->label('Opening hours')
                ->placeholder($locale === 'uz' ? 'Du–Sha 10:00–20:00' : null)
                ->maxLength(255),
            Forms\Components\TextInput::make("membership.{$locale}")
                ->label('Membership')
                ->placeholder($locale === 'uz' ? "A'zolik bepul, pasport bilan" : null)
                ->maxLength(255),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')->label('')->disk('public'),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('district')
                    ->description(fn (Library $library) => $library->region)
                    ->searchable(),
                Tables\Columns\TextColumn::make('holdings_count')->label('Books')->counts('holdings')->sortable(),
                Tables\Columns\TextColumn::make('holdings_updated_at')
                    ->label('List updated')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->color(fn (Library $library) => self::isStale($library) ? 'warning' : null),
                Tables\Columns\IconColumn::make('user_id')
                    ->label('Login')
                    ->boolean()
                    ->getStateUsing(fn (Library $library) => $library->user_id !== null),
                Tables\Columns\ToggleColumn::make('is_published')
                    ->label('On the site')
                    ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false)),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('On the site'),
                Tables\Filters\SelectFilter::make('region')->options(Regions::options()),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function isStale(Library $library): bool
    {
        return $library->holdings_updated_at === null
            || $library->holdings_updated_at->lt(now()->subDays(Library::STALE_AFTER_DAYS));
    }

    /** An owner's list is their one library; see PublisherResource. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->isLibrary()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLibraries::route('/'),
            'create' => Pages\CreateLibrary::route('/create'),
            'edit' => Pages\EditLibrary::route('/{record}/edit'),
            'import' => Pages\ImportHoldings::route('/{record}/import'),
        ];
    }
}
