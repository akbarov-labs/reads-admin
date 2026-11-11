<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Filament\Resources\ProfileSuggestionResource\Pages;
use App\Filament\Support\PendingNavigationBadge;
use App\Models\Author;
use App\Models\ProfileSuggestion;
use App\Models\Translator;
use App\Services\ProfileSuggestions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Researched facts about authors and translators (a bio in three
 * languages, years, nationality, a translator's role and city) waiting for
 * an admin, loaded by profiles:import-suggestions. Review shows them beside
 * the pages they came from; Publish writes them to the person's page.
 */
class ProfileSuggestionResource extends Resource
{
    use PendingNavigationBadge;

    protected static ?string $model = ProfileSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Author & translator suggestions';

    protected static ?string $modelLabel = 'author or translator suggestion';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subject'))
            ->columns([
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Author::class ? 'Author' : 'Translator')
                    ->color(fn (string $state) => $state === Author::class ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('subject.name')
                    ->label('Name')
                    ->weight('medium')
                    ->description(fn (ProfileSuggestion $record) => self::years($record))
                    ->url(fn (ProfileSuggestion $record) => self::editUrl($record), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'high' => 'success',
                        'medium' => 'warning',
                        'low' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('bio')
                    ->label('Found')
                    ->wrap()
                    ->getStateUsing(fn (ProfileSuggestion $record) => $record->bio['uz'] ?? null)
                    ->limit(110)
                    ->placeholder('No bio, facts only')
                    ->description(fn (ProfileSuggestion $record) => $record->note ? 'Check: '.Str::limit($record->note, 70) : null),
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
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Kind')
                    ->options([Author::class => 'Authors', Translator::class => 'Translators']),
                Tables\Filters\SelectFilter::make('confidence')
                    ->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
            ])
            ->actions([
                self::reviewAction(),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Nothing reaches the page, and loading the file again will not bring this back.')
                    ->visible(fn (ProfileSuggestion $record) => $record->status === ModerationStatus::Pending)
                    ->action(fn (ProfileSuggestion $record) => app(ProfileSuggestions::class)->reject($record)),
            ])
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("case confidence when 'high' then 0 when 'medium' then 1 when 'low' then 2 else 3 end")
                ->orderBy('id'))
            ->recordAction('review')
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Researched facts appear here after php artisan profiles:import-suggestions.');
    }

    /**
     * The review: what was found and where, beside the form of what the
     * page will show. Fields the page already has keep its value; a
     * different finding is one click away.
     */
    private static function reviewAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('review')
            ->label('Review')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (ProfileSuggestion $record) => $record->status === ModerationStatus::Pending)
            ->slideOver()
            ->modalWidth('3xl')
            ->modalHeading(fn (ProfileSuggestion $record) => $record->subject->name)
            ->modalDescription('What you publish here goes on the page. Check the sources first.')
            ->modalSubmitActionLabel('Publish')
            ->fillForm(fn (ProfileSuggestion $record) => ProfileSuggestions::formState($record))
            ->form(fn (ProfileSuggestion $record) => [
                Forms\Components\Section::make('What was found')
                    ->schema([
                        Forms\Components\Placeholder::make('found')
                            ->hiddenLabel()
                            ->content(fn () => self::foundSummary($record)),
                    ]),
                Forms\Components\Section::make('On the page')
                    ->schema([
                        ...($record->isAuthor() ? [
                            self::withFound(Forms\Components\TextInput::make('original_name')->label('Name in its own language')->maxLength(255), $record),
                            self::withFound(Forms\Components\TextInput::make('birth_year')->label('Born')->numeric()->minValue(1)->maxValue((int) date('Y')), $record),
                            self::withFound(Forms\Components\TextInput::make('death_year')->label('Died')->numeric()->minValue(1)->maxValue((int) date('Y')), $record),
                        ] : []),
                        Forms\Components\Tabs::make('Languages')->tabs(array_map(
                            fn (string $locale, string $tab) => Forms\Components\Tabs\Tab::make($tab)->schema(self::localeFields($record, $locale)),
                            ProfileSuggestions::LOCALES,
                            ['Uzbek', 'Russian', 'English'],
                        ))->columnSpanFull(),
                    ])->columns(3),
            ])
            ->action(function (ProfileSuggestion $record, array $data) {
                $subject = app(ProfileSuggestions::class)->publish($record, $data);

                Notification::make()->success()->title("{$subject->name}: published on the site.")->send();
            });
    }

    /** @return array<int, Forms\Components\Field> */
    private static function localeFields(ProfileSuggestion $record, string $locale): array
    {
        $fields = $record->isAuthor()
            ? [Forms\Components\TextInput::make("nationality.{$locale}")->label('Country')->maxLength(100)]
            : [
                Forms\Components\TextInput::make("title.{$locale}")->label('Role line')->maxLength(160),
                Forms\Components\TextInput::make("location.{$locale}")->label('City')->maxLength(160),
            ];
        $fields[] = Forms\Components\Textarea::make("bio.{$locale}")->label('Bio')->rows(7);

        return array_map(fn (Forms\Components\Field $field) => self::withFound($field, $record, $locale), $fields);
    }

    /**
     * Where the page already has a value and the finding differs, the
     * finding under the field and a button that puts it in. From the Uzbek
     * tab, one press takes all three languages.
     */
    private static function withFound(Forms\Components\Field $field, ProfileSuggestion $record, ?string $locale = null): Forms\Components\Field
    {
        $name = $locale !== null ? explode('.', $field->getName())[0] : $field->getName();
        $subject = $record->subject;
        $found = $locale !== null ? ($record->{$name}[$locale] ?? null) : $record->{$name};
        $current = $locale !== null ? $subject->getTranslation($name, $locale, false) : $subject->{$name};

        if (blank($found) || blank($current) || (string) $found === (string) $current) {
            return $field;
        }

        return $field->hintAction(
            Forms\Components\Actions\Action::make('useFound')
                ->label($locale === 'uz' ? 'Use the found ones (all 3 languages)' : 'Use the found one')
                ->action(function (Forms\Set $set) use ($field, $found, $locale, $record, $name) {
                    if ($locale !== 'uz') {
                        $set($field->getName(), $found);

                        return;
                    }

                    foreach (ProfileSuggestions::LOCALES as $each) {
                        if (filled($record->{$name}[$each] ?? null)) {
                            $set("{$name}.{$each}", $record->{$name}[$each]);
                        }
                    }
                }),
        )->helperText(new HtmlString('<strong>Found:</strong> '.nl2br(e((string) $found))));
    }

    private static function foundSummary(ProfileSuggestion $record): HtmlString
    {
        $link = fn (string $url, string $label) => '<a href="'.e($url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'.e($label).'</a>';
        $subject = $record->subject;

        $rows = array_filter([
            // A translator who signed in looks after their own page: their
            // own words should not be replaced without a reason.
            'Note' => $subject instanceof Translator && $subject->user_id !== null
                ? '<strong style="color:rgb(217 119 6)">This translator has an account and may have written their page themselves.</strong>'
                : null,
            'Confidence' => $record->confidence ? e(ucfirst($record->confidence)) : null,
            'Check' => $record->note ? '<strong>'.e($record->note).'</strong>' : null,
            'Found in' => $record->sources
                ? implode(' · ', array_map(fn (string $url) => $link($url, Str::limit(preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/')), 45)), $record->sources))
                : null,
            'Page' => $link(self::editUrl($record), 'Open in the admin'),
        ]);

        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;font-size:.875rem">';
        foreach ($rows as $label => $value) {
            $html .= '<dt style="color:rgb(107 114 128)">'.$label.'</dt><dd>'.$value.'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }

    private static function years(ProfileSuggestion $record): ?string
    {
        if ($record->birth_year === null && $record->death_year === null) {
            return null;
        }

        return ($record->birth_year ?? '?').'–'.($record->death_year ?? '');
    }

    private static function editUrl(ProfileSuggestion $record): string
    {
        return $record->isAuthor()
            ? AuthorResource::getUrl('edit', ['record' => $record->subject])
            : TranslatorResource::getUrl('edit', ['record' => $record->subject]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfileSuggestions::route('/'),
        ];
    }
}
