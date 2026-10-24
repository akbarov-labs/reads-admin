<?php

namespace App\Filament\Clicks;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** How many readers each store got, and for how many different books. */
class TopStoresTable extends TableWidget
{
    use FiltersClicks;

    protected static ?string $heading = 'Top stores';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            // Grouped rows have no id of their own; the lowest click id in
            // each group stands in, since Filament keys every row.
            ->query(
                $this->clicks()
                    ->select('target')
                    ->selectRaw('min(id) as id, count(*) as clicks, count(distinct visitor_hash) as visitors, count(distinct book_id) as books')
                    ->groupBy('target')
            )
            ->defaultSort('clicks', 'desc')
            ->columns([
                TextColumn::make('target')->label('Store'),
                TextColumn::make('clicks')->label('Clicks')->numeric()->sortable(),
                TextColumn::make('visitors')->label('Visitors')->numeric()->sortable(),
                TextColumn::make('books')->label('Books')->numeric()->sortable(),
            ])
            ->paginated(false);
    }
}
