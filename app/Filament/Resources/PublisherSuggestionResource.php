<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Filament\Resources\PublisherSuggestionResource\Pages;
use App\Filament\Support\PendingNavigationBadge;
use App\Models\PublisherSuggestion;
use App\Services\PublisherSuggestions;
use App\Services\Telegram\TelegramChannel;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Details found for publishers (a Telegram channel, contacts, an About
 * text, the channel's photo) waiting for an admin, loaded by
 * publishers:import-suggestions. Review opens the details beside links to
 * where they were found; Publish writes them to the publisher's page.
 */
class PublisherSuggestionResource extends Resource
{
    use PendingNavigationBadge;

    protected static ?string $model = PublisherSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'Publisher suggestions';

    protected static ?string $modelLabel = 'publisher suggestion';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_path')->label('')->disk('public')->circular()->height(40),
                Tables\Columns\TextColumn::make('publisher.name')
                    ->label('Publisher')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (PublisherSuggestion $record) => $record->publisher->slug)
                    ->url(fn (PublisherSuggestion $record) => PublisherResource::getUrl('edit', ['record' => $record->publisher]), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('telegram_channel')
                    ->label('Telegram channel')
                    ->formatStateUsing(fn (string $state) => "@{$state}")
                    ->placeholder('No channel found')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->iconPosition('after')
                    ->color('primary')
                    ->url(fn (PublisherSuggestion $record) => $record->channelUrl(), shouldOpenInNewTab: true)
                    ->description(fn (PublisherSuggestion $record) => $record->subscribers !== null ? number_format($record->subscribers, 0, '.', ' ').' subscribers' : null),
                Tables\Columns\TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        'low' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('evidence')
                    ->label('Why')
                    ->wrap()
                    ->limit(90)
                    ->tooltip(fn (PublisherSuggestion $record) => $record->evidence)
                    ->description(fn (PublisherSuggestion $record) => ($owner = $record->channelOwner())
                        ? "Channel already belongs to {$owner->name}"
                        : ($record->note ? 'Check: '.Str::limit($record->note, 60) : null)),
                Tables\Columns\TextColumn::make('status')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ModerationStatus::Pending->value => 'Awaiting review',
                        ModerationStatus::Approved->value => 'Published',
                        ModerationStatus::Rejected->value => 'Rejected',
                    ])
                    ->default(ModerationStatus::Pending->value),
                Tables\Filters\SelectFilter::make('confidence')
                    ->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
                Tables\Filters\TernaryFilter::make('telegram_channel')
                    ->label('Channel')
                    ->nullable()
                    ->trueLabel('Channel found')
                    ->falseLabel('No channel'),
            ])
            ->actions([
                Tables\Actions\Action::make('channel')
                    ->label('Open the channel')
                    ->icon('heroicon-o-paper-airplane')
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (PublisherSuggestion $record) => $record->telegram_channel !== null)
                    ->url(fn (PublisherSuggestion $record) => $record->channelUrl(), shouldOpenInNewTab: true),
                self::reviewAction(),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Nothing reaches the publisher\'s page, and loading the file again will not bring this back.')
                    ->visible(fn (PublisherSuggestion $record) => $record->status === ModerationStatus::Pending)
                    ->action(fn (PublisherSuggestion $record) => app(PublisherSuggestions::class)->reject($record)),
            ])
            ->recordAction('review')
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Details found for publishers appear here after php artisan publishers:import-suggestions.');
    }

    /**
     * The review: what was found and where, beside the form of what the
     * page will show. Fields the publisher already has keep its value; a
     * different find is one click away in the field's hint.
     */
    private static function reviewAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('review')
            ->label('Review')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (PublisherSuggestion $record) => $record->status === ModerationStatus::Pending)
            ->slideOver()
            ->modalWidth('3xl')
            ->modalHeading(fn (PublisherSuggestion $record) => $record->publisher->name)
            ->modalDescription('What you publish here goes on the publisher\'s page. Check the channel first.')
            ->modalSubmitActionLabel('Publish')
            ->fillForm(fn (PublisherSuggestion $record) => PublisherSuggestions::formState($record))
            ->form(fn (PublisherSuggestion $record) => [
                Forms\Components\Section::make('What was found')
                    ->schema([
                        Forms\Components\Placeholder::make('found')
                            ->hiddenLabel()
                            ->content(fn () => self::foundSummary($record)),
                    ]),
                Forms\Components\Section::make('Logo')
                    ->visible($record->logo_path !== null)
                    ->schema([
                        Forms\Components\Placeholder::make('photo')
                            ->hiddenLabel()
                            ->content(new HtmlString('<img src="'.e(Storage::disk('public')->url((string) $record->logo_path)).'" alt="" style="width:96px;height:96px;border-radius:9999px;object-fit:cover">')),
                        Forms\Components\Toggle::make('use_logo')
                            ->label('Use the channel photo as the logo')
                            ->helperText($record->publisher->logo_path ? 'The publisher has a logo already: turning this on replaces it.' : null),
                    ])->columns(2),
                Forms\Components\Section::make('On the publisher\'s page')
                    ->schema([
                        self::withFound(Forms\Components\TextInput::make('telegram_channel')
                            ->label('Telegram channel')
                            ->prefix('@')
                            ->rule('regex:'.TelegramChannel::USERNAME)
                            ->helperText('Read every hour for new books once published.')
                            ->suffixAction(Forms\Components\Actions\Action::make('open')
                                ->icon('heroicon-m-arrow-top-right-on-square')
                                ->tooltip('Open in Telegram')
                                ->url(fn (?string $state) => filled($state) ? 'https://t.me/'.TelegramChannel::username($state) : null, shouldOpenInNewTab: true))
                            ->dehydrateStateUsing(fn (?string $state) => TelegramChannel::username($state)), $record),
                        self::withFound(Forms\Components\TextInput::make('order_contact')
                            ->label('Telegram for orders')
                            ->prefix('@')
                            ->rule('regex:'.TelegramChannel::USERNAME)
                            ->dehydrateStateUsing(fn (?string $state) => TelegramChannel::username($state)), $record),
                        self::withFound(PublisherResource::shopField(), $record),
                        self::withFound(Forms\Components\TextInput::make('website_url')->label('Website')->url()->maxLength(255), $record),
                        self::withFound(Forms\Components\TextInput::make('phone')->tel()->maxLength(32), $record),
                        self::withFound(Forms\Components\TextInput::make('instagram')
                            ->label('Instagram')
                            ->prefix('@')
                            ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if (filled($value) && PublisherResource::instagramUsername($value) === null) {
                                    $fail('This is not an Instagram username.');
                                }
                            }])
                            ->dehydrateStateUsing(fn (?string $state) => PublisherResource::instagramUsername($state)), $record),
                        self::withFound(Forms\Components\TextInput::make('established_year')->label('Founded')->numeric()->minValue(1800)->maxValue((int) date('Y')), $record),
                        self::withFound(Forms\Components\TextInput::make('address')->maxLength(255)->columnSpanFull(), $record),
                        Forms\Components\Tabs::make('About')->tabs([
                            Forms\Components\Tabs\Tab::make('Uzbek')->schema([
                                self::withFound(Forms\Components\TextInput::make('motto.uz')->label('Motto')->maxLength(160), $record, 'uz'),
                                self::withFound(Forms\Components\Textarea::make('bio.uz')->label('About')->rows(7), $record, 'uz'),
                            ]),
                            Forms\Components\Tabs\Tab::make('Russian')->schema([
                                self::withFound(Forms\Components\TextInput::make('motto.ru')->label('Motto')->maxLength(160), $record, 'ru'),
                                self::withFound(Forms\Components\Textarea::make('bio.ru')->label('About')->rows(7), $record, 'ru'),
                            ]),
                            Forms\Components\Tabs\Tab::make('English')->schema([
                                self::withFound(Forms\Components\TextInput::make('motto.en')->label('Motto')->maxLength(160), $record, 'en'),
                                self::withFound(Forms\Components\Textarea::make('bio.en')->label('About')->rows(7), $record, 'en'),
                            ]),
                        ])->columnSpanFull(),
                    ])->columns(2),
            ])
            ->action(function (PublisherSuggestion $record, array $data) {
                $publisher = app(PublisherSuggestions::class)->publish($record, $data);

                Notification::make()->success()->title("{$publisher->name}: published on the site.")->send();
            });
    }

    /**
     * Where the publisher already has a value and the find differs, a hint
     * that puts the find in the field.
     */
    private static function withFound(Forms\Components\Field $field, PublisherSuggestion $record, ?string $locale = null): Forms\Components\Field
    {
        // A text by locale ("bio.uz", "motto.ru") or a plain field.
        $name = $locale !== null ? explode('.', $field->getName())[0] : $field->getName();
        $found = $locale !== null ? ($record->{$name}[$locale] ?? null) : $record->{$name};
        $current = $locale !== null ? $record->publisher->getTranslation($name, $locale, false) : $record->publisher->{$name};

        if (blank($found) || blank($current) || (string) $found === (string) $current) {
            return $field;
        }

        // An About text is too long for a hint: show the whole find under
        // the field, so the two can be read side by side.
        if ($locale !== null) {
            return $field->hintAction(
                Forms\Components\Actions\Action::make('useFound')
                    // From the first tab, one press takes all three languages.
                    ->label($locale === 'uz' ? 'Use the found '.($name === 'motto' ? 'mottos' : 'texts').' (all 3 languages)' : 'Use the found '.($name === 'motto' ? 'motto' : 'text'))
                    ->action(function (Forms\Set $set) use ($field, $found, $locale, $record, $name) {
                        if ($locale !== 'uz') {
                            $set($field->getName(), $found);

                            return;
                        }

                        foreach (PublisherSuggestions::LOCALES as $each) {
                            if (filled($record->{$name}[$each] ?? null)) {
                                $set("{$name}.{$each}", $record->{$name}[$each]);
                            }
                        }
                    }),
            )->helperText(new HtmlString('<strong>Found:</strong> '.nl2br(e((string) $found))));
        }

        return $field->hintAction(
            Forms\Components\Actions\Action::make('useFound')
                ->label('Use found: '.Str::limit((string) $found, 40))
                ->tooltip((string) $found)
                ->action(fn (Forms\Set $set) => $set($field->getName(), $found)),
        )->helperText('Now on the page. The search found something else.');
    }

    private static function foundSummary(PublisherSuggestion $record): HtmlString
    {
        $link = fn (string $url, string $label) => '<a href="'.e($url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'.e($label).'</a>';
        $links = array_filter([
            $record->channelUrl() ? $link($record->channelUrl(), '@'.$record->telegram_channel.($record->subscribers !== null ? ' ('.number_format($record->subscribers, 0, '.', ' ').' subscribers)' : '')) : null,
            $record->shop_url ? $link($record->shop_url, 'Shop: '.(parse_url($record->shop_url, PHP_URL_HOST) === 't.me' ? '@'.ltrim((string) parse_url($record->shop_url, PHP_URL_PATH), '/') : (parse_url($record->shop_url, PHP_URL_HOST) ?: $record->shop_url))) : null,
            $record->website_url ? $link($record->website_url, parse_url($record->website_url, PHP_URL_HOST) ?: $record->website_url) : null,
            $record->instagram ? $link("https://www.instagram.com/{$record->instagram}/", 'Instagram @'.$record->instagram) : null,
            $link(PublisherResource::getUrl('edit', ['record' => $record->publisher]), 'Publisher in the admin'),
        ]);

        $owner = $record->channelOwner();

        $rows = array_filter([
            'Warning' => $owner ? '<strong style="color:rgb(220 38 38)">@'.e($record->telegram_channel).' is already the channel of '.$link(PublisherResource::getUrl('edit', ['record' => $owner]), $owner->name).'. Probably the same publisher entered twice: merge them instead of publishing the channel here.</strong>' : null,
            'Confidence' => $record->confidence ? e(ucfirst($record->confidence)) : null,
            'Why' => $record->evidence ? e($record->evidence) : null,
            'Also seen' => $record->other_channels ? e($record->other_channels) : null,
            'Check' => $record->note ? '<strong>'.e($record->note).'</strong>' : null,
            'About text from' => $record->about_sources
                ? implode(' · ', array_map(fn (string $url) => $link($url, Str::limit(preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/')), 45)), $record->about_sources))
                : null,
            'Links' => implode(' · ', $links),
        ]);

        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;font-size:.875rem">';
        foreach ($rows as $label => $value) {
            $html .= '<dt style="color:rgb(107 114 128)">'.$label.'</dt><dd>'.$value.'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }

    public static function getEloquentQuery(): Builder
    {
        // Surest finds first, then the biggest channels.
        return parent::getEloquentQuery()
            ->with('publisher')
            ->orderByRaw("case confidence when 'high' then 0 when 'medium' then 1 when 'low' then 2 else 3 end")
            ->orderByDesc('subscribers');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublisherSuggestions::route('/'),
        ];
    }
}
