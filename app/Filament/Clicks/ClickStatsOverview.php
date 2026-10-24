<?php

namespace App\Filament\Clicks;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClickStatsOverview extends StatsOverviewWidget
{
    use FiltersClicks;

    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $totals = $this->clicks()
            ->toBase()
            ->selectRaw('count(*) as clicks, count(distinct visitor_hash) as visitors, count(distinct book_id) as books')
            ->first();

        $top = $this->targets()[0] ?? null;

        return [
            Stat::make('Clicks to stores', number_format((int) $totals->clicks)),
            // The visitor hash changes every day, so someone who came back
            // on three days is three here. Close enough to tell a store
            // how many people, and it is all the privacy design allows.
            Stat::make('Visitors', number_format((int) $totals->visitors))
                ->description('Each counted once a day'),
            Stat::make('Books', number_format((int) $totals->books))
                ->description('With at least one click'),
            Stat::make('Busiest store', $top ?? '—'),
        ];
    }
}
