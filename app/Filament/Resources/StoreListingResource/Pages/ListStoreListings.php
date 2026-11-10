<?php

namespace App\Filament\Resources\StoreListingResource\Pages;

use App\Filament\Resources\StoreListingResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStoreListings extends ListRecords
{
    protected static string $resource = StoreListingResource::class;

    public function getSubheading(): ?string
    {
        return 'Books on sale at qamar.uz, matched to the catalogue by ISBN. Read each night; only blanks are ever filled.';
    }

    public function getTabs(): array
    {
        $tabs = [
            'new' => ['New to us', fn (Builder $query) => $query->whereNotNull('isbn')->whereNull('edition_id')->whereNotNull('applied_at')],
            'differences' => ['Differences', fn (Builder $query) => $query->whereNotNull('edition_id')->whereNotNull('differences')],
            'filled' => ['Filled', fn (Builder $query) => $query->whereNotNull('filled')],
            'all' => ['All', fn (Builder $query) => $query],
        ];

        return collect($tabs)
            ->map(fn (array $tab) => Tab::make($tab[0])
                ->badge(fn () => $tab[1](StoreListingResource::getModel()::query())->count())
                ->modifyQueryUsing($tab[1]))
            ->all();
    }

    public function getDefaultActiveTab(): string
    {
        return 'new';
    }
}
