<?php

namespace App\Filament\Stats;

use App\Models\SearchQuery;
use App\Support\SiteStats;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

class ViewsAndSearchesChart extends ChartWidget
{
    use FiltersStats;

    protected static ?string $heading = 'Book views and searches per day';

    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = collect(CarbonPeriod::create(
            CarbonImmutable::parse($this->from()),
            CarbonImmutable::parse($this->until()),
        ))->map(fn ($day) => $day->toDateString());

        $views = $this->views()
            ->toBase()
            ->selectRaw('day, sum(views) as views')
            ->groupBy('day')
            ->pluck('views', 'day');

        $searches = $this->searches()
            ->toBase()
            ->selectRaw(SiteStats::daySql().' as day, count(*) as searches')
            ->selectRaw('sum(case when source = ? and results = 0 then 1 else 0 end) as empty', [SearchQuery::SOURCE_PAGE])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $line = fn (string $label, string $colour, callable $value) => [
            'label' => $label,
            'data' => $days->map(fn (string $day) => (int) $value($day))->all(),
            'borderColor' => $colour,
            'backgroundColor' => $colour,
            'tension' => 0.2,
        ];

        return [
            'datasets' => [
                $line('Book views', '#1F4D2E', fn ($day) => $views[$day] ?? 0),
                $line('Searches', '#2563EB', fn ($day) => $searches[$day]->searches ?? 0),
                $line('Searches with no results', '#C2410C', fn ($day) => $searches[$day]->empty ?? 0),
            ],
            'labels' => $days->all(),
        ];
    }
}
