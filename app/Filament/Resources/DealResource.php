<?php

namespace App\Filament\Resources;

use App\Enums\DealKind;
use App\Enums\ModerationStatus;
use App\Filament\Resources\DealResource\Pages;
use App\Filament\Support\PendingNavigationBadge;
use App\Models\Book;
use App\Models\Deal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The review queue for discounts and contests. A draft comes from the
 * hourly scan of publishers' channels (a post about a sale or a contest),
 * a pasted post link (a shop's channel too), or is typed in by hand for a
 * shop's promotion on its own site. Published deals show on the site's
 * Chegirmalar page until their last day (Deal::scopeLive).
 */
class DealResource extends Resource
{
    use PendingNavigationBadge;

    protected static ?string $model = Deal::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Deals';

    protected static ?string $navigationGroup = 'On the site';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The post')
                ->description('Check every field against the post before publishing: they were read from its text and may be wrong.')
                ->schema([
                    Forms\Components\Placeholder::make('source')
                        ->label('Telegram post')
                        ->content(fn (?Deal $record) => $record?->source_url ?? '—'),
                    Forms\Components\Placeholder::make('posted')
                        ->label('Posted')
                        ->content(fn (?Deal $record) => $record?->posted_at?->timezone('Asia/Tashkent')->format('j M Y, H:i') ?? '—'),
                    Forms\Components\Textarea::make('post_text')
                        ->label('Text of the post')
                        ->rows(8)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->visible(fn (?Deal $record) => $record?->source_url !== null),
            Forms\Components\Section::make('On the site')->schema([
                Forms\Components\Select::make('kind')
                    ->options(DealKind::class)
                    ->default(DealKind::Discount->value)
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options([
                        ModerationStatus::Pending->value => 'Awaiting review',
                        ModerationStatus::Approved->value => 'Published',
                        ModerationStatus::Rejected->value => 'Rejected',
                    ])
                    ->default(ModerationStatus::Pending->value)
                    ->required(),
                Forms\Components\Select::make('publisher_id')
                    ->label('Publisher')
                    ->relationship('publisher', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText('Or, for a shop that is not a publisher, its name below.'),
                Forms\Components\TextInput::make('shop_name')
                    ->label('Shop')
                    ->placeholder('Asaxiy, Qamar, Zabarjad…')
                    ->maxLength(64)
                    ->required(fn (Get $get) => blank($get('publisher_id'))),
                Forms\Components\TextInput::make('title')
                    ->label('Headline')
                    ->placeholder('Barcha kitoblarga 30% chegirma')
                    ->helperText('One line in Uzbek, as a reader would say it.')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('details')
                    ->label('Conditions')
                    ->helperText('Which books, minimum order, how to enter. A few lines.')
                    ->rows(4)
                    ->maxLength(600)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('discount_percent')
                    ->label('Discount')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(95)
                    ->suffix('%'),
                Forms\Components\TextInput::make('promo_code')
                    ->label('Promo code')
                    ->maxLength(32)
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper(trim($state)) : null),
                Forms\Components\DatePicker::make('starts_on')
                    ->label('From')
                    ->native(false)
                    ->helperText('Empty: already on.'),
                Forms\Components\DatePicker::make('ends_on')
                    ->label('Until (last day)')
                    ->native(false)
                    ->helperText('Empty: shown for '.Deal::UNDATED_FOR_DAYS.' days from the post.'),
                Forms\Components\TextInput::make('url')
                    ->label('Where to take it up')
                    ->url()
                    ->maxLength(512)
                    ->helperText('The shop\'s page for the sale. Empty: the Telegram post.')
                    ->columnSpanFull(),
                Forms\Components\Select::make('book_id')
                    ->label('Book in the catalogue')
                    ->helperText('Only for a deal on one book.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Book::query()
                        ->where('uzbek_title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orderBy('uzbek_title')
                        ->limit(50)
                        ->pluck('uzbek_title', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Book::find($value)?->uzbek_title),
                Forms\Components\FileUpload::make('image_path')
                    ->label('Picture')
                    ->image()
                    ->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)
                    ->maxSize(5120)
                    ->disk('public')
                    ->directory('deals')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('')->disk('public')->height(56),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap()
                    ->description(fn (Deal $record) => $record->sourceName()),
                Tables\Columns\TextColumn::make('kind')->badge()
                    ->color(fn (Deal $record) => $record->kind === DealKind::Contest ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('discount_percent')->label('%')->suffix('%')->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_on')->label('Until')->date('j M Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->description(fn (Deal $record) => $record->found_by_scan ? 'Found by scan' : ($record->source_url ? 'Pasted' : 'Typed in')),
                Tables\Columns\TextColumn::make('clicks')->label('Clicks')->state(fn (Deal $record) => $record->clicksCount()),
                Tables\Columns\TextColumn::make('posted_at')->label('Posted')->date('j M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    ModerationStatus::Pending->value => 'Awaiting review',
                    ModerationStatus::Approved->value => 'Published',
                    ModerationStatus::Rejected->value => 'Rejected',
                ]),
                Tables\Filters\SelectFilter::make('kind')->options(DealKind::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Publish')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Deal $record) => $record->status !== ModerationStatus::Approved)
                    ->action(fn (Deal $record) => $record->update(['status' => ModerationStatus::Approved])),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (Deal $record) => $record->status === ModerationStatus::Pending)
                    ->action(fn (Deal $record) => $record->update(['status' => ModerationStatus::Rejected])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('post')
                    ->label('Post')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Deal $record) => $record->source_url !== null)
                    ->url(fn (Deal $record) => $record->source_url, shouldOpenInNewTab: true),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('publisher');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeals::route('/'),
            'create' => Pages\CreateDeal::route('/create'),
            'edit' => Pages\EditDeal::route('/{record}/edit'),
        ];
    }
}
