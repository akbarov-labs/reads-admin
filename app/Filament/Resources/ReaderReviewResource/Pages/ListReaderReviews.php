<?php

namespace App\Filament\Resources\ReaderReviewResource\Pages;

use App\Filament\Resources\ReaderReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListReaderReviews extends ListRecords
{
    protected static string $resource = ReaderReviewResource::class;

    // No create action: reader reviews only ever arrive from the bot.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
