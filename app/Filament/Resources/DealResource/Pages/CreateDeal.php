<?php

namespace App\Filament\Resources\DealResource\Pages;

use App\Filament\Resources\DealResource;
use Filament\Resources\Pages\CreateRecord;

/** A shop's promotion on its own site, with no Telegram post to paste. */
class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;
}
