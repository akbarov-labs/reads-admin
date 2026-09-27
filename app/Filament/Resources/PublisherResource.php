<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource\Pages;
use App\Filament\Resources\PublisherResource\RelationManagers\EditionsRelationManager;
use App\Models\Publisher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class PublisherResource extends Resource
{
    protected static ?string $model = Publisher::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Publishers';

    /**
     * Publishers now sign in to maintain their own entry and add the books
     * they publish, so unlike the other shared catalogue resources this is
     * not admin-only. A publisher's list is scoped to their single row.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || $user?->isPublisher());
    }

    public static function getRelations(): array
    {
        return [EditionsRelationManager::class];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Login account')
                ->description('Credentials the publisher uses to sign in. Only admins can see or change these.')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->helperText('Leave blank if this publisher has nobody maintaining it yet.')
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
                            ? 'Share this with the publisher — it will not be shown again.'
                            : 'Leave blank to keep the current password.')
                        ->default(fn (string $context) => $context === 'create' ? Str::password(12) : null)
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columns(2),

            Forms\Components\Section::make('Publisher identity')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(255)
                    // Admin-only in the UI; PublisherObserver is what
                    // actually stops a publisher moving their own URL.
                    ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false)),
                Forms\Components\TextInput::make('website_url')->url()->maxLength(255),
                Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)->maxSize(4096)->disk('public')->directory('publishers'),
            ])->columns(2),
            Forms\Components\Section::make('Localized information')->schema([
                Forms\Components\Tabs::make('Locales')->tabs([
                    Forms\Components\Tabs\Tab::make('Uzbek')->schema(self::localizedFields('uz')),
                    Forms\Components\Tabs\Tab::make('Russian')->schema(self::localizedFields('ru')),
                    Forms\Components\Tabs\Tab::make('English')->schema(self::localizedFields('en')),
                ])->columnSpanFull(),
            ]),
            Forms\Components\TextInput::make('established_year')->numeric()->minValue(1)->maxValue(9999),
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    private static function localizedFields(string $locale): array
    {
        return [
            Forms\Components\Textarea::make("bio.{$locale}")->label('About')->rows(3),
            Forms\Components\TextInput::make("country.{$locale}")->label('Country'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('logo_path')->label('')->disk('public'),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->searchable(),
            Tables\Columns\TextColumn::make('books_count')->label('Books')->sortable(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withBookCount();
        $user = auth()->user();

        // Scoped for publishers only, not for every non-admin. A translator
        // is neither, and PublisherPolicy already refuses them with a 403 —
        // narrowing the query for them as well would turn that into a 404
        // and quietly change what the panel tells them.
        if ($user?->isPublisher()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublishers::route('/'),
            'create' => Pages\CreatePublisher::route('/create'),
            'edit' => Pages\EditPublisher::route('/{record}/edit'),
        ];
    }
}