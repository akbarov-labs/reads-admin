<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use App\Models\Collection;
use Filament\Resources\Pages\CreateRecord;

class CreateCollection extends CreateRecord
{
    protected static string $resource = CollectionResource::class;

    /** A collection named but not given an address gets one from its Uzbek title. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['slug'] ?? null)) {
            $data['slug'] = Collection::generateUniqueSlug((string) ($data['title']['uz'] ?? 'toplam'));
        }

        return $data;
    }
}
