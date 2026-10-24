<?php

namespace App\Filament\Stats;

use App\Models\SearchQuery;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/** What readers search for most, on the books listing and in the search box. */
class TopSearchesTable extends SearchesTable
{
    protected static ?string $heading = 'Top searches';

    protected function scope(Builder $query): Builder
    {
        return $query
            ->selectRaw('sum(case when source = ? then 1 else 0 end) as picks', [SearchQuery::SOURCE_BOX])
            ->selectRaw('max(results) as found');
    }

    protected function extraColumns(): array
    {
        return [
            TextColumn::make('picks')->label('Picked a suggestion')->numeric()->sortable(),
            // Only listing searches report a count; a search only ever made
            // in the box has none.
            TextColumn::make('found')->label('Books found')->numeric()->placeholder('—'),
        ];
    }
}
