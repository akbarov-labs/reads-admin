<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TranslatorResource\Pages;
use App\Filament\Resources\TranslatorResource\RelationManagers\BooksRelationManager;
use App\Filament\Resources\TranslatorResource\RelationManagers\LanguagePairsRelationManager;
use App\Filament\Resources\TranslatorResource\RelationManagers\SocialLinksRelationManager;
use App\Models\Translator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranslatorResource extends Resource
{
    protected static ?string $model = Translator::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Translators';

    /**
     * Only admins manage translators through the list/table — a translator
     * gets their own profile via the dashboard widget's "Edit my profile"
     * button instead, so there's no point cluttering their nav with a
     * one-row list of just themselves.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Login account')
                ->description('Credentials the translator uses to sign in. Only admins can see or change these.')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(
                            table: 'users',
                            column: 'email',
                            modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, $record) => $record?->user_id
                                ? $rule->ignore($record->user_id)
                                : $rule,
                        ),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->label(fn (string $context) => $context === 'create' ? 'Password' : 'Reset password')
                        ->helperText(fn (string $context) => $context === 'create'
                            ? 'Share this with the translator — it will not be shown again.'
                            : 'Leave blank to keep the current password.')
                        ->default(fn (string $context) => $context === 'create' ? \Illuminate\Support\Str::password(12) : null)
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columns(2),

            Forms\Components\Section::make('Profile')
                ->schema([
                    Forms\Components\FileUpload::make('avatar_path')
                        ->label('Avatar')
                        ->image()
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
                        ->helperText('Used in the public profile URL, e.g. /translator/your-slug'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Translated profile content')
                ->description("Shown on the public page in the visitor's chosen language (Uzbek, Russian, or English). Leave a language blank and it falls back to Uzbek.")
                ->schema([
                    Forms\Components\Tabs::make('Locales')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')
                                ->schema(self::translatedProfileFields('uz')),
                            Forms\Components\Tabs\Tab::make('Russian')
                                ->schema(self::translatedProfileFields('ru')),
                            Forms\Components\Tabs\Tab::make('English')
                                ->schema(self::translatedProfileFields('en')),
                        ]),
                ]),

            Forms\Components\Section::make('Contact & experience')
                ->description('Social/contact links (Telegram, Instagram, YouTube, etc.) are managed below, in the "Social links" tab.')
                ->schema([
                    Forms\Components\TextInput::make('years_active')
                        ->required()
                        ->numeric()
                        ->default(0),
                    Forms\Components\TextInput::make('start_year')
                        ->numeric(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    protected static function translatedProfileFields(string $locale): array
    {
        return [
            Forms\Components\TextInput::make("title.{$locale}")
                ->label('Professional title')
                ->placeholder('e.g. Translator & Author')
                ->maxLength(255),
            Forms\Components\TextInput::make("location.{$locale}")
                ->label('Location')
                ->maxLength(255),
            Forms\Components\Textarea::make("bio.{$locale}")
                ->label('Bio')
                ->rows(4)
                ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('location')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('books_count')
                    ->counts('books')
                    ->label('Books')
                    ->sortable(),
                Tables\Columns\TextColumn::make('language_pairs_count')
                    ->counts('languagePairs')
                    ->label('Language pairs')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LanguagePairsRelationManager::class,
            SocialLinksRelationManager::class,
            BooksRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['books', 'languagePairs']);

        if (auth()->user()?->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTranslators::route('/'),
            'create' => Pages\CreateTranslator::route('/create'),
            'edit' => Pages\EditTranslator::route('/{record}/edit'),
        ];
    }
}
