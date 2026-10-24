<?php

namespace App\Filament\Pages;

use App\Enums\OutboundClickKind;
use App\Filament\Stats\ClicksPerDayChart;
use App\Filament\Stats\NoResultSearchesTable;
use App\Filament\Stats\SiteStatsOverview;
use App\Filament\Stats\TopAnnouncementsTable;
use App\Filament\Stats\TopBooksTable;
use App\Filament\Stats\TopSearchesTable;
use App\Filament\Stats\TopStoresTable;
use App\Filament\Stats\ViewsAndSearchesChart;
use App\Models\OutboundClick;
use App\Support\SiteStats as Stats;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Illuminate\Support\Facades\Auth;

/**
 * The site's own numbers, same day: which books readers open, which they
 * go on to look for in a store (and which store), and what they search for,
 * found or not. The store figures are the ones to show a store or a
 * publisher what Kitoblarim sends them.
 *
 * A second dashboard rather than widgets on the first, so its filters
 * (dates, store, language) apply to every chart and table on it at once.
 * Its widgets live outside app/Filament/Widgets so the main dashboard does
 * not pick them up; AdminPanelProvider registers them with Livewire.
 */
class SiteStats extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'stats';

    protected static ?string $title = 'Site stats';

    protected static ?string $navigationLabel = 'Site stats';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 90;

    /** The window shown before anyone picks dates: the last 30 days. */
    public const DEFAULT_DAYS = 30;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function defaultFrom(): string
    {
        return CarbonImmutable::now(Stats::TIMEZONE)->subDays(self::DEFAULT_DAYS - 1)->toDateString();
    }

    public static function defaultUntil(): string
    {
        return CarbonImmutable::now(Stats::TIMEZONE)->toDateString();
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')
                ->label('From')
                ->native(false)
                ->default(self::defaultFrom())
                ->maxDate(fn () => self::defaultUntil()),
            DatePicker::make('until')
                ->label('Until')
                ->native(false)
                ->default(self::defaultUntil()),
            Select::make('target')
                ->label('Store')
                ->helperText('Narrows store clicks only')
                ->placeholder('All stores')
                ->options(fn () => OutboundClick::query()->where('kind', OutboundClickKind::Store)->distinct()->orderBy('target')->pluck('target', 'target')->all()),
            Select::make('locale')
                ->label('Site language')
                ->placeholder('All languages')
                ->options(['uz' => "O'zbekcha", 'ru' => 'Русский', 'en' => 'English']),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            SiteStatsOverview::class,
            ViewsAndSearchesChart::class,
            ClicksPerDayChart::class,
            TopBooksTable::class,
            TopStoresTable::class,
            TopAnnouncementsTable::class,
            TopSearchesTable::class,
            NoResultSearchesTable::class,
        ];
    }

    public function getColumns(): int | string | array
    {
        return 1;
    }
}
