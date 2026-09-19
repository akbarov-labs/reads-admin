<?php

namespace App\Filament\Resources\TaqrizResource\Pages;

use App\Filament\Resources\TaqrizResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaqrizlar extends ListRecords
{
    protected static string $resource = TaqrizResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
