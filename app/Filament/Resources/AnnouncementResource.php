<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Filament\Resources\AnnouncementResource\Pages;
use App\Filament\Support\PendingNavigationBadge;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Edition;
use App\Services\CatalogueSync;
use App\Services\Telegram\TelegramChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The review queue for publishers' new books and pre-orders. A draft comes
 * from the hourly scan of publishers' Telegram channels (announcements:scan)
 * or a pasted post link (Pages\ListAnnouncements), with what could be read
 * from the post filled in; an admin checks it against the post and
 * publishes it. Pre-orders leave the site after their deadline,
 * new books after Announcement::NEW_FOR_DAYS.
 */
class AnnouncementResource extends Resource
{
    use PendingNavigationBadge;

    protected static ?string $model = Announcement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $navigationGroup = 'On the site';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Announcement::query()->where('status', ModerationStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The post')
                ->description('Check every field against the post before publishing: they were read from its text and may be wrong.')
                ->schema([
                    Forms\Components\Placeholder::make('source')
                        ->label('Telegram post')
                        ->content(fn (?Announcement $record) => $record?->source_url ?? '—'),
                    Forms\Components\Placeholder::make('posted')
                        ->label('Posted')
                        ->content(fn (?Announcement $record) => $record?->posted_at?->timezone('Asia/Tashkent')->format('j M Y, H:i') ?? '—'),
                    Forms\Components\Textarea::make('post_text')
                        ->label('Text of the post')
                        ->rows(8)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])->columns(2)->collapsible(),
            Forms\Components\Section::make('On the site')->schema([
                Forms\Components\Select::make('publisher_id')
                    ->relationship('publisher', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options([
                        ModerationStatus::Pending->value => 'Awaiting review',
                        ModerationStatus::Approved->value => 'Published',
                        ModerationStatus::Rejected->value => 'Rejected',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\TextInput::make('author_name')->label('Author')->maxLength(255),
                Forms\Components\TextInput::make('translator_name')->label('Translator')->maxLength(255),
                Forms\Components\TextInput::make('price')
                    ->label('Price')
                    ->numeric()
                    ->minValue(0)
                    ->suffix("so'm"),
                Forms\Components\TextInput::make('preorder_price')
                    ->label('Pre-order price')
                    ->numeric()
                    ->minValue(0)
                    ->suffix("so'm"),
                Forms\Components\DatePicker::make('preorder_deadline')
                    ->label('Pre-order until (last day)')
                    ->native(false)
                    ->helperText('Leave empty for a book already in print. A pre-order leaves the site the day after this.'),
                Forms\Components\Select::make('book_id')
                    ->label('Book in the catalogue')
                    ->helperText('Leave empty and publishing makes the book from this announcement (or links the one already there with the same ISBN, or title and author).')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Book::query()
                        ->where('uzbek_title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orderBy('uzbek_title')
                        ->limit(50)
                        ->pluck('uzbek_title', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Book::find($value)?->uzbek_title),
                Forms\Components\FileUpload::make('cover_path')
                    ->label('Cover')
                    ->image()
                    ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                    ->maxSize(5120)
                    ->disk('public')
                    ->directory('announcements')
                    ->columnSpanFull(),
                self::orderContactField(),
            ])->columns(2),
            Forms\Components\Section::make('Book details')
                ->description('Saved to the catalogue edition when the announcement is published.')
                ->schema([
                    ...self::bookFields(),
                    Forms\Components\Placeholder::make('book_link')
                        ->label('Book')
                        ->content(fn (?Announcement $record) => $record?->book ? new HtmlString('<a class="underline" href="'.e(BookResource::getUrl('edit', ['record' => $record->book])).'">'.e($record->book->uzbek_title).' — edit the book</a>') : '—')
                        ->visible(fn (?Announcement $record) => $record?->book_id !== null)
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    /** A username, "@name" or t.me link, kept as the bare username. */
    public static function orderContactField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('order_contact')
            ->label('Telegram for orders')
            ->prefix('@')
            ->helperText('Only for this book. Empty: the publisher\'s order account.')
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && TelegramChannel::username((string) $value) === null) {
                    $fail('That is not a Telegram username.');
                }
            })
            ->dehydrateStateUsing(fn (?string $state) => TelegramChannel::username($state));
    }

    /**
     * What a post rarely says and the catalogue wants. Shown only while the
     * announcement has no book: after that, the book's own page is where
     * its edition is edited.
     *
     * @return list<Forms\Components\Component>
     */
    public static function bookFields(): array
    {
        $unlinked = fn (?Announcement $record) => $record?->book_id === null;

        return [
            Forms\Components\TextInput::make('isbn')
                ->label('ISBN')
                ->maxLength(32)
                ->helperText('13 digits, hyphens allowed. A book with this ISBN in the catalogue is linked instead of made again.')
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    if (filled($value) && CatalogueSync::normalizeIsbn((string) $value) === null) {
                        $fail('An ISBN has 13 digits.');
                    }
                })
                ->visible($unlinked),
            Forms\Components\TextInput::make('pages')->numeric()->minValue(1)->maxValue(10000)->visible($unlinked),
            Forms\Components\TextInput::make('year')->numeric()->minValue(Edition::FIRST_YEAR)->maxValue(Edition::lastYear())->visible($unlinked),
            Forms\Components\Textarea::make('description')->rows(4)->columnSpanFull()->visible($unlinked),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')->label('')->disk('public')->height(56),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap()
                    ->description(fn (Announcement $record) => collect([$record->author_name, $record->translator_name])->filter()->implode(' · ') ?: null),
                Tables\Columns\TextColumn::make('publisher.name')->label('Publisher')->searchable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->state(fn (Announcement $record) => $record->isPreorder()
                        ? 'Pre-order'.($record->preorder_deadline ? ' until '.$record->preorder_deadline->format('j M') : '')
                        : 'New book')
                    ->color(fn (Announcement $record) => $record->isPreorder() ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('preorder_price')
                    ->label('Price')
                    ->state(fn (Announcement $record) => collect([$record->preorder_price, $record->price])->filter()->map(fn (int $sum) => number_format($sum, 0, '.', ' '))->implode(' / ') ?: null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->description(fn (Announcement $record) => $record->found_by_scan ? 'Found by scan' : 'Pasted'),
                Tables\Columns\TextColumn::make('clicks_count')->label('Orders')->counts('clicks')->sortable(),
                Tables\Columns\TextColumn::make('posted_at')->label('Posted')->date('j M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Publish')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Announcement $record) => $record->status !== ModerationStatus::Approved)
                    // Asks for the details the catalogue needs, so a row can be
                    // published without opening it.
                    ->fillForm(fn (Announcement $record) => $record->only(['order_contact', 'isbn', 'pages', 'year', 'description']))
                    ->form(fn (Announcement $record) => [
                        self::orderContactField(),
                        ...($record->book_id === null ? self::bookFields() : []),
                    ])
                    ->modalHeading('Publish and add to the catalogue')
                    ->action(fn (Announcement $record, array $data) => $record->update([...$data, 'status' => ModerationStatus::Approved])),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (Announcement $record) => $record->status === ModerationStatus::Pending)
                    ->action(fn (Announcement $record) => $record->update(['status' => ModerationStatus::Rejected])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('post')
                    ->label('Post')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Announcement $record) => $record->source_url, shouldOpenInNewTab: true),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('publisher');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
