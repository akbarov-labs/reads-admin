<?php

namespace App\Filament\Stats;

use App\Filament\Resources\BookResource;
use App\Models\Book;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Which books readers opened, and which they then went looking for in a
 * store, with a column per store.
 */
class TopBooksTable extends TableWidget
{
    use FiltersStats;

    protected static ?string $heading = 'Top books';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $targets = $this->targets();

        $views = $this->views()
            ->toBase()
            ->select('book_id')
            ->selectRaw('sum(views) as views')
            ->groupBy('book_id');

        $clicks = $this->clicks()
            ->toBase()
            ->whereNotNull('book_id')
            ->select('book_id')
            ->selectRaw('count(*) as clicks, count(distinct visitor_hash) as visitors')
            ->groupBy('book_id');

        // A column per store. The alias is the store's position, not its
        // name, so nothing from the table ends up spelled into the SQL.
        foreach ($targets as $i => $target) {
            $clicks->selectRaw("sum(case when target = ? then 1 else 0 end) as store_{$i}", [$target]);
        }

        $query = Book::query()
            ->leftJoinSub($views, 'v', 'v.book_id', '=', 'books.id')
            ->leftJoinSub($clicks, 'c', 'c.book_id', '=', 'books.id')
            ->where(fn ($query) => $query->whereNotNull('v.book_id')->orWhereNotNull('c.book_id'))
            ->select('books.*')
            ->selectRaw('coalesce(v.views, 0) as views, coalesce(c.clicks, 0) as clicks, coalesce(c.visitors, 0) as visitors');

        foreach (array_keys($targets) as $i) {
            $query->selectRaw("coalesce(c.store_{$i}, 0) as store_{$i}");
        }

        $storeColumns = collect($targets)->map(
            fn (string $target, int $i) => TextColumn::make("store_{$i}")
                ->label($target)
                ->numeric()
                ->sortable()
        )->all();

        return $table
            ->query($query)
            ->defaultSort('views', 'desc')
            ->columns([
                TextColumn::make('uzbek_title')
                    ->label('Book')
                    ->searchable(query: fn ($query, string $search) => $query->where('books.uzbek_title', 'like', "%{$search}%"))
                    ->url(fn (Book $record) => BookResource::getUrl('edit', ['record' => $record]))
                    ->wrap(),
                TextColumn::make('views')->label('Views')->numeric()->sortable(),
                TextColumn::make('clicks')->label('Store clicks')->numeric()->sortable(),
                TextColumn::make('click_through')
                    ->label('Click-through')
                    ->state(fn (Book $record) => $record->views > 0
                        ? number_format(100 * $record->clicks / $record->views, 1).'%'
                        : '—'),
                TextColumn::make('visitors')->label('Clicking visitors')->numeric()->sortable(),
                ...$storeColumns,
            ])
            ->paginated([10, 25, 50]);
    }
}
