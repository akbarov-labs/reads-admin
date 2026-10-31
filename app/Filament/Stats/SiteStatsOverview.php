<?php

namespace App\Filament\Stats;

use App\Enums\OutboundClickKind;
use App\Models\SearchQuery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SiteStatsOverview extends StatsOverviewWidget
{
    use FiltersStats;

    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $views = (int) $this->views()->sum('views');

        $clicks = $this->clicks()
            ->toBase()
            ->selectRaw('count(*) as clicks, count(distinct visitor_hash) as visitors')
            ->first();

        $searches = $this->searches()
            ->toBase()
            ->selectRaw('count(*) as searches')
            ->selectRaw('sum(case when source = ? then 1 else 0 end) as listings', [SearchQuery::SOURCE_PAGE])
            ->selectRaw('sum(case when source = ? and results = 0 then 1 else 0 end) as empty', [SearchQuery::SOURCE_PAGE])
            ->first();

        $listings = (int) $searches->listings;

        return [
            Stat::make('Book views', number_format($views))
                ->description('A reload within 30 minutes is one view'),
            // The visitor hash changes every day, so someone who came back
            // on three days is three here. Close enough to tell a store
            // how many people, and it is all the privacy design allows.
            Stat::make('Store clicks', number_format((int) $clicks->clicks))
                ->description('From '.number_format((int) $clicks->visitors).' visitors, each counted once a day'),
            Stat::make('Click-through', $views > 0 ? $this->percent((int) $clicks->clicks, $views) : '—')
                ->description('Store clicks per book view'),
            Stat::make('Searches', number_format((int) $searches->searches))
                ->description(number_format((int) $searches->searches - $listings).' picked from suggestions'),
            Stat::make('Searches with no results', number_format((int) $searches->empty))
                ->description($listings > 0 ? $this->percent((int) $searches->empty, $listings).' of listing searches' : 'On the books listing')
                ->color((int) $searches->empty > 0 ? 'warning' : null),
            Stat::make('Busiest store', $this->targets()[0] ?? '—'),
            Stat::make('Publisher orders', number_format($this->clicks(OutboundClickKind::PublisherOrder)->count()))
                ->description('Order buttons pressed on announcements'),
            $this->askAi($views),
        ];
    }

    /**
     * Readers who asked an AI about a page's subject, by kind of page, and
     * how that compares with book views: the one number that says whether
     * the button earns its place near the top of the page.
     */
    private function askAi(int $views): Stat
    {
        $bySubject = $this->clicks(OutboundClickKind::AskAi)
            ->toBase()
            ->select('target')
            ->selectRaw('count(*) as clicks')
            ->groupBy('target')
            ->orderByDesc('clicks')
            ->orderBy('target')
            ->pluck('clicks', 'target');

        $total = (int) $bySubject->sum();
        $parts = $bySubject->map(fn ($clicks, string $subject) => $subject.' '.number_format((int) $clicks))->values();

        if ($views > 0) {
            $parts->push($this->percent((int) ($bySubject['book'] ?? 0), $views).' of book views');
        }

        return Stat::make('Ask AI clicks', number_format($total))
            ->description($total > 0 ? $parts->implode(', ') : 'Ask-AI buttons pressed on book, author and other pages');
    }

    private function percent(int $part, int $whole): string
    {
        return number_format(100 * $part / $whole, 1).'%';
    }
}
