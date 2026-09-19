<?php

namespace App\Filament\Resources\TaqrizchiResource\Pages;

use App\Filament\Resources\TaqrizchiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaqrizchilar extends ListRecords
{
    protected static string $resource = TaqrizchiResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
