<?php

namespace App\Filament\Stats;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searches grouped by what was asked: "Oʻtkan kunlar", "ЎТКАН КУНЛАР" and
 * "otkan kunlar" are one row (query_key), shown in one of the spellings
 * readers used.
 */
abstract class SearchesTable extends TableWidget
{
    use FiltersStats;

    protected int | string | array $columnSpan = 'full';

    /** Narrows the searches this table counts, or adds columns to the grouped select. */
    abstract protected function scope(Builder $query): Builder;

    /** Columns after the query, searches and visitors. */
    protected function extraColumns(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            // Grouped rows have no id of their own; the lowest search id in
            // each group stands in, since Filament keys every row.
            ->query(
                $this->scope(
                    $this->searches()
                        ->select('query_key')
                        ->selectRaw('min(id) as id, max(query) as query, count(*) as searches, count(distinct visitor_hash) as visitors, max(created_at) as last_searched_at')
                        ->groupBy('query_key')
                )
            )
            ->defaultSort('searches', 'desc')
            ->columns([
                TextColumn::make('query')->label('Search')->wrap(),
                TextColumn::make('searches')->label('Searches')->numeric()->sortable(),
                TextColumn::make('visitors')->label('Visitors')->numeric()->sortable(),
                ...$this->extraColumns(),
                TextColumn::make('last_searched_at')->label('Last')->since()->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
