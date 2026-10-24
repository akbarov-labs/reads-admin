<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Enums\ModerationStatus;
use App\Filament\Resources\AnnouncementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label('Open the post')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => $this->record->source_url, shouldOpenInNewTab: true),
            // Saves what is in the form and publishes in one go: the usual
            // end of a review.
            Actions\Action::make('publish')
                ->label('Save and publish')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn () => $this->record->status !== ModerationStatus::Approved)
                ->action(function () {
                    $this->data['status'] = ModerationStatus::Approved->value;
                    $this->save();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
