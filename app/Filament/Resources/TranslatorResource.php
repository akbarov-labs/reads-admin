<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TranslatorResource\Pages;
use App\Filament\Resources\TranslatorResource\RelationManagers\EditionsRelationManager;
use App\Filament\Resources\TranslatorResource\RelationManagers\LanguagePairsRelationManager;
use App\Filament\RelationManagers\SocialLinksRelationManager;
use App\Models\Translator;
use App\Support\TranslatorSeo;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class TranslatorResource extends Resource
{
    protected static ?string $model = Translator::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationLabel = 'Translators';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 3;

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
                ->description('Credentials the translator uses to sign in. Only admins can see or change these. Leave blank if this is a name-only entry with nobody signing in yet.')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->helperText('Leave blank if this translator has nobody maintaining it yet.')
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
                            ? 'Share this with the translator — it will not be shown again.'
                            : 'Leave blank to keep the current password.')
                        ->default(fn (string $context) => $context === 'create' ? Str::password(12) : null)
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columns(2),

            Forms\Components\Section::make('Profile')
                ->schema([
                    Forms\Components\FileUpload::make('avatar_path')
                        ->label('Avatar')
                        ->image()
                        ->acceptedFileTypes(self::SAFE_IMAGE_TYPES)
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

            Forms\Components\Section::make('Search engine (SEO)')
                ->description('How this profile appears in Google and when its link is shared. Admin-only: translators never see or edit this section, and a write from a non-admin is rejected server-side (see App\Observers\TranslatorObserver).')
                ->icon('heroicon-o-magnifying-glass')
                ->collapsed()
                ->visible(fn () => auth()->user()?->isAdmin())
                ->schema([
                    Forms\Components\Placeholder::make('seo_intro')
                        ->hiddenLabel()
                        ->content('Leave a field blank and the site generates it from the profile itself — the name, professional title, location, language pairs and current book count. Those generated values update on their own as editions are added, so blank is a good default. Fill a field in only to override.')
                        ->columnSpanFull(),

                    Forms\Components\Tabs::make('SEO locales')
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')
                                ->schema(self::seoFields('uz')),
                            Forms\Components\Tabs\Tab::make('Russian')
                                ->schema(self::seoFields('ru')),
                            Forms\Components\Tabs\Tab::make('English')
                                ->schema(self::seoFields('en')),
                        ])
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('og_image_path')
                        ->label('Social share image')
                        ->helperText('Shown when the profile link is pasted into Telegram, Facebook or X. 1200×630 works everywhere. Falls back to the avatar when empty.')
                        ->image()
                        ->acceptedFileTypes(self::SAFE_IMAGE_TYPES)
                        ->maxSize(4096)
                        ->imageEditor()
                        ->imageEditorAspectRatios(['1200:630'])
                        ->disk('public')
                        ->directory('og')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('seo_noindex')
                        ->label('Hide from search engines')
                        ->helperText('Adds noindex and drops the profile from the sitemap. The page stays reachable by direct link.'),
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
     * Uploads are served from this app's own origin, so an SVG here would be
     * a stored-XSS vector (an SVG can carry <script>). Raster formats only —
     * there is no legitimate need for a vector avatar or book cover.
     *
     * @var array<int, string>
     */
    public const SAFE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array<Component>
     */
    protected static function seoFields(string $locale): array
    {
        return [
            Forms\Components\TextInput::make("meta_title.{$locale}")
                ->label('Meta title')
                ->maxLength(70)
                ->placeholder(fn (?Translator $record) => $record
                    ? TranslatorSeo::for($record)->defaultTitle($locale)
                    : 'Generated from the name and professional title')
                ->helperText('Around 60 characters. The grey text is what will be used if you leave this empty.')
                ->columnSpanFull(),
            Forms\Components\Textarea::make("meta_description.{$locale}")
                ->label('Meta description')
                ->rows(3)
                ->maxLength(200)
                ->placeholder(fn (?Translator $record) => $record
                    ? TranslatorSeo::for($record)->defaultDescription($locale)
                    : 'Generated from the bio, or from the book count and language pairs')
                ->helperText('Around 155 characters. This is the snippet under the search result.')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<Component>
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
                    ->placeholder('No account yet')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('location')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('editions_count')
                    ->counts('editions')
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
            EditionsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['editions', 'languagePairs']);

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
