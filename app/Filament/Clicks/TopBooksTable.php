<?php

namespace App\Filament\Clicks;

use App\Filament\Resources\BookResource;
use App\Models\Book;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Which books readers went looking for in a store, with a column per store. */
class TopBooksTable extends TableWidget
{
    use FiltersClicks;

    protected static ?string $heading = 'Top books';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $targets = $this->targets();

        $perBook = $this->clicks()
            ->toBase()
            ->whereNotNull('book_id')
            ->select('book_id')
            ->selectRaw('count(*) as clicks, count(distinct visitor_hash) as visitors')
            ->groupBy('book_id');

        // A column per store. The alias is the store's position, not its
        // name, so nothing from the table ends up spelled into the SQL.
        foreach ($targets as $i => $target) {
            $perBook->selectRaw("sum(case when target = ? then 1 else 0 end) as store_{$i}", [$target]);
        }

        $storeColumns = collect($targets)->map(
            fn (string $target, int $i) => TextColumn::make("store_{$i}")
                ->label($target)
                ->numeric()
                ->sortable()
        )->all();

        return $table
            ->query(
                Book::query()
                    ->joinSub($perBook, 'counts', 'counts.book_id', '=', 'books.id')
                    ->select('books.*', 'counts.*')
            )
            ->defaultSort('clicks', 'desc')
            ->columns([
                TextColumn::make('uzbek_title')
                    ->label('Book')
                    ->searchable(query: fn ($query, string $search) => $query->where('books.uzbek_title', 'like', "%{$search}%"))
                    ->url(fn (Book $record) => BookResource::getUrl('edit', ['record' => $record]))
                    ->wrap(),
                TextColumn::make('clicks')->label('Clicks')->numeric()->sortable(),
                TextColumn::make('visitors')->label('Visitors')->numeric()->sortable(),
                ...$storeColumns,
            ])
            ->paginated([10, 25, 50]);
    }
}
