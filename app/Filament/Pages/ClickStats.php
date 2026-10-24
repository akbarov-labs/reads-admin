<?php

namespace App\Filament\Pages;

use App\Filament\Clicks\ClicksPerDayChart;
use App\Filament\Clicks\ClickStatsOverview;
use App\Filament\Clicks\TopBooksTable;
use App\Filament\Clicks\TopStoresTable;
use App\Models\OutboundClick;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Illuminate\Support\Facades\Auth;

/**
 * How many readers the site sent to each store, for which books, and when:
 * the numbers to show a store or a publisher what Kitoblarim sends them.
 *
 * A second dashboard rather than widgets on the first, so its filters
 * (dates, store, language) apply to every chart and table on it at once.
 * Its widgets live outside app/Filament/Widgets so the main dashboard does
 * not pick them up; AdminPanelProvider registers them with Livewire.
 */
class ClickStats extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'clicks';

    protected static ?string $title = 'Store clicks';

    protected static ?string $navigationLabel = 'Store clicks';

    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?int $navigationSort = 90;

    /** The window shown before anyone picks dates: the last 30 days. */
    public const DEFAULT_DAYS = 30;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function defaultFrom(): string
    {
        return CarbonImmutable::now(OutboundClick::TIMEZONE)->subDays(self::DEFAULT_DAYS - 1)->toDateString();
    }

    public static function defaultUntil(): string
    {
        return CarbonImmutable::now(OutboundClick::TIMEZONE)->toDateString();
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
                ->placeholder('All stores')
                ->options(fn () => OutboundClick::query()->distinct()->orderBy('target')->pluck('target', 'target')->all()),
            Select::make('locale')
                ->label('Site language')
                ->placeholder('All languages')
                ->options(['uz' => "O'zbekcha", 'ru' => 'Русский', 'en' => 'English']),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            ClickStatsOverview::class,
            ClicksPerDayChart::class,
            TopBooksTable::class,
            TopStoresTable::class,
        ];
    }

    public function getColumns(): int | string | array
    {
        return 1;
    }
}
