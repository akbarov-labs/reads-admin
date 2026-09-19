<?php

namespace App\Filament\Resources\TaqrizchiResource\Pages;

use App\Filament\Resources\TaqrizchiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaqrizchi extends EditRecord
{
    protected static string $resource = TaqrizchiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ];
    }

    /**
     * The login fields live on the same form as the profile, so the email
     * has to be read back off the related user when the form opens.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->record->user?->email;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = $this->record->user;

        if ($user && auth()->user()?->isAdmin()) {
            $user->fill(array_filter([
                'email' => $data['email'] ?? null,
                // Blank means "keep the current password", so it is filtered
                // out rather than written as an empty hash.
                'password' => $data['password'] ?? null,
            ]));
            $user->save();
        }

        unset($data['email'], $data['password']);

        return $data;
    }
}
