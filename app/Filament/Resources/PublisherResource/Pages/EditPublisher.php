<?php

namespace App\Filament\Resources\PublisherResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPublisher extends EditRecord
{
    protected static string $resource = PublisherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->record->user?->email;

        return $data;
    }

    /**
     * Only an admin can attach or change the login. A publisher editing their
     * own page never sees these fields, and the observer pins user_id anyway.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        unset($data['email'], $data['password']);

        if (! (auth()->user()?->isAdmin() ?? false)) {
            return $data;
        }

        $user = $this->record->user;

        if ($user) {
            $user->fill(array_filter([
                'email' => $email,
                // Blank means "keep the current password".
                'password' => $password,
            ]));
            $user->save();
        } elseif (filled($email)) {
            // Giving an existing catalogue entry an owner for the first time.
            $data['user_id'] = User::create([
                'name' => $this->record->name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Publisher,
            ])->id;
        }

        return $data;
    }
}
