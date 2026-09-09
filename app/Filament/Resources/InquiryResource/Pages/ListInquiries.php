<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;

    // No create action — inquiries only ever arrive through the public API.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
