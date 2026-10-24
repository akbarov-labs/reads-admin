<?php

namespace App\Filament\Clicks;

use App\Models\OutboundClick;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

class ClicksPerDayChart extends ChartWidget
{
    use FiltersClicks;

    protected static ?string $heading = 'Clicks per day';

    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    /** One colour per store, in order of clicks. */
    private const COLOURS = ['#1F4D2E', '#C2410C', '#2563EB', '#9333EA', '#CA8A04', '#0F766E', '#BE123C'];

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

        $counts = $this->clicks()
            ->toBase()
            ->selectRaw(OutboundClick::daySql().' as day, target, count(*) as clicks')
            ->groupBy('day', 'target')
            ->get()
            ->groupBy('target');

        $datasets = collect($this->targets())->values()->map(function (string $target, int $i) use ($counts, $days) {
            $byDay = ($counts[$target] ?? collect())->pluck('clicks', 'day');
            $colour = self::COLOURS[$i % count(self::COLOURS)];

            return [
                'label' => $target,
                'data' => $days->map(fn (string $day) => (int) ($byDay[$day] ?? 0))->all(),
                'borderColor' => $colour,
                'backgroundColor' => $colour,
                'tension' => 0.2,
            ];
        });

        return [
            'datasets' => $datasets->all(),
            'labels' => $days->all(),
        ];
    }
}
