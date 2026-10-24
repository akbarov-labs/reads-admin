<?php

namespace App\Filament\Stats;

use App\Enums\OutboundClickKind;
use App\Filament\Resources\AnnouncementResource;
use App\Models\Announcement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Which announcements sent readers to their publisher to order: the numbers to show a publisher. */
class TopAnnouncementsTable extends TableWidget
{
    use FiltersStats;

    protected static ?string $heading = 'Publisher orders';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $orders = $this->clicks(OutboundClickKind::PublisherOrder)
            ->toBase()
            ->select('announcement_id')
            ->selectRaw('count(*) as orders, count(distinct visitor_hash) as visitors')
            ->groupBy('announcement_id');

        return $table
            ->query(
                Announcement::query()
                    ->with('publisher')
                    ->joinSub($orders, 'o', 'o.announcement_id', '=', 'announcements.id')
                    ->select('announcements.*', 'o.orders', 'o.visitors')
            )
            ->defaultSort('orders', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Announcement')
                    ->url(fn (Announcement $record) => AnnouncementResource::getUrl('edit', ['record' => $record]))
                    ->wrap(),
                TextColumn::make('publisher.name')->label('Publisher'),
                TextColumn::make('orders')->label('Order clicks')->numeric()->sortable(),
                TextColumn::make('visitors')->label('Visitors')->numeric()->sortable(),
            ])
            ->emptyStateHeading('No order clicks yet')
            ->paginated([10, 25, 50]);
    }
}
