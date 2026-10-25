<?php

namespace App\Filament\Resources;

use App\Filament\RelationManagers\SocialLinksRelationManager;
use App\Filament\Resources\TaqrizchiResource\Pages;
use App\Models\Taqrizchi;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * @see TranslatorResource — deliberately the same shape. A reviewer edits
 * their own profile through the dashboard widget rather than a list of one,
 * so the navigation entry is admin-only.
 */
class TaqrizchiResource extends Resource
{
    protected static ?string $model = Taqrizchi::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Taqrizchilar';

    protected static ?string $navigationGroup = 'Reviews';

    protected static ?int $navigationSort = 3;

    /** @see TaqrizResource::$slug — "taqrizchis" for the same reason. */
    protected static ?string $slug = 'taqrizchilar';

    protected static ?string $modelLabel = 'taqrizchi';

    protected static ?string $pluralModelLabel = 'taqrizchilar';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Login account')
                ->description('Credentials the taqrizchi uses to sign in. Only admins can see or change these.')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
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
                            ? 'Share this with the taqrizchi — it will not be shown again.'
                            : 'Leave blank to keep the current password.')
                        ->default(fn (string $context) => $context === 'create' ? Str::password(12) : null)
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columns(2),

            Forms\Components\Section::make('Profile')
                ->schema([
                    Forms\Components\FileUpload::make('avatar_path')
                        ->label('Avatar')
                        ->image()
                        ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                        ->maxSize(4096)
                        ->avatar()
                        ->disk('public')
                        ->directory('avatars'),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        // Admin-only in the UI; TaqrizchiObserver is what
                        // actually stops a reviewer moving their own URL.
                        ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false))
                        ->helperText('Used in the public profile URL, e.g. /taqrizchi/your-slug'),
                ])
                ->columns(2),

            Forms\Components\Section::make('About')
                ->description('Each language is optional — the site falls back to Uzbek where one is blank.')
                ->schema([
                    Forms\Components\Tabs::make('Locales')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')->schema(self::localizedFields('uz')),
                            Forms\Components\Tabs\Tab::make('Russian')->schema(self::localizedFields('ru')),
                            Forms\Components\Tabs\Tab::make('English')->schema(self::localizedFields('en')),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<int, Component> */
    private static function localizedFields(string $locale): array
    {
        return [
            Forms\Components\TextInput::make("title.{$locale}")
                ->label('Professional title')
                ->placeholder('Taqrizchi'),
            Forms\Components\TextInput::make("location.{$locale}")
                ->label('Location'),
            Forms\Components\Textarea::make("bio.{$locale}")
                ->label('Biography')
                ->rows(4),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('taqrizlar_count')
                    ->counts('taqrizlar')
                    ->label('Taqrizlar')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [SocialLinksRelationManager::class];
    }

    /**
     * A taqrizchi sees only themselves. The policy authorises the record;
     * this stops the list from showing other people's rows at all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('taqrizlar');
        $user = auth()->user();

        if ($user && ! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaqrizchilar::route('/'),
            'create' => Pages\CreateTaqrizchi::route('/create'),
            'edit' => Pages\EditTaqrizchi::route('/{record}/edit'),
        ];
    }
}
