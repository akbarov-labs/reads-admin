<?php

namespace App\Filament\Resources\TaqrizResource\Pages;

use App\Enums\TaqrizStatus;
use App\Filament\Resources\TaqrizResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaqriz extends EditRecord
{
    protected static string $resource = TaqrizResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Editing a published taqriz returns it to the queue — TaqrizObserver
     * does that for a reviewer, and this is the notice explaining why the
     * badge changed under them.
     */
    protected function getSavedNotificationTitle(): ?string
    {
        return $this->record->status === TaqrizStatus::Pending
            ? 'Saved — sent for review'
            : 'Saved';
    }
}
