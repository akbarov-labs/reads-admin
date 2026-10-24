<?php

namespace App\Filament\Stats;

use App\Filament\Pages\SiteStats;
use App\Models\BookView;
use App\Models\OutboundClick;
use App\Models\SearchQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the SiteStats page's filters select, for each of its widgets. Dates
 * and site language narrow everything; the store narrows clicks only.
 */
trait FiltersStats
{
    use InteractsWithPageFilters;

    // The date pickers' state can carry a time ("2026-09-25 11:56:21"); only
    // the day counts, or a range could end a few hours short of its last day.
    protected function from(): string
    {
        return CarbonImmutable::parse($this->filters['from'] ?? SiteStats::defaultFrom())->toDateString();
    }

    protected function until(): string
    {
        return CarbonImmutable::parse($this->filters['until'] ?? SiteStats::defaultUntil())->toDateString();
    }

    protected function locale(): ?string
    {
        return $this->filters['locale'] ?? null;
    }

    /** @return Builder<OutboundClick> */
    protected function clicks(): Builder
    {
        return OutboundClick::query()
            ->betweenDays($this->from(), $this->until())
            ->when($this->filters['target'] ?? null, fn (Builder $query, string $target) => $query->where('target', $target))
            ->when($this->locale(), fn (Builder $query, string $locale) => $query->where('locale', $locale));
    }

    /** @return Builder<BookView> */
    protected function views(): Builder
    {
        return BookView::query()
            ->betweenDays($this->from(), $this->until())
            ->when($this->locale(), fn (Builder $query, string $locale) => $query->where('locale', $locale));
    }

    /** @return Builder<SearchQuery> */
    protected function searches(): Builder
    {
        return SearchQuery::query()
            ->betweenDays($this->from(), $this->until())
            ->when($this->locale(), fn (Builder $query, string $locale) => $query->where('locale', $locale));
    }

    /**
     * The stores in the current selection, busiest first: one table column
     * and one chart line each.
     *
     * @return list<string>
     */
    protected function targets(): array
    {
        return $this->clicks()
            ->toBase()
            ->select('target')
            ->groupBy('target')
            ->orderByRaw('count(*) desc')
            ->orderBy('target')
            ->pluck('target')
            ->all();
    }
}
