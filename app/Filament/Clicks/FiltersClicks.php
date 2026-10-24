<?php

namespace App\Filament\Clicks;

use App\Filament\Pages\ClickStats;
use App\Models\OutboundClick;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

/** The clicks the ClickStats page's filters select, for each of its widgets. */
trait FiltersClicks
{
    use InteractsWithPageFilters;

    // The date pickers' state can carry a time ("2026-09-25 11:56:21"); only
    // the day counts, or a range could end a few hours short of its last day.
    protected function from(): string
    {
        return CarbonImmutable::parse($this->filters['from'] ?? ClickStats::defaultFrom())->toDateString();
    }

    protected function until(): string
    {
        return CarbonImmutable::parse($this->filters['until'] ?? ClickStats::defaultUntil())->toDateString();
    }

    /** @return Builder<OutboundClick> */
    protected function clicks(): Builder
    {
        return OutboundClick::query()
            ->betweenDays($this->from(), $this->until())
            ->when($this->filters['target'] ?? null, fn (Builder $query, string $target) => $query->where('target', $target))
            ->when($this->filters['locale'] ?? null, fn (Builder $query, string $locale) => $query->where('locale', $locale));
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
