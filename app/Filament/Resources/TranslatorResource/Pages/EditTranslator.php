<?php

namespace App\Filament\Resources\TranslatorResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\TranslatorResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditTranslator extends EditRecord
{
    protected static string $resource = TranslatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->record->user?->email;

        return $data;
    }

    /**
     * @see PublisherResource\Pages\EditPublisher — same shape. A name-only
     * translator can be given a login here for the first time, same as an
     * unclaimed publisher row.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $email = Arr::pull($data, 'email');
        $password = Arr::pull($data, 'password');

        $updates = array_filter(
            ['email' => $email, 'password' => $password],
            fn ($value) => filled($value),
        );

        $user = $this->record->user;

        if ($user) {
            if ($updates !== []) {
                $user->update($updates);
            }
        } elseif (filled($email)) {
            $data['user_id'] = User::create([
                'name' => $this->record->name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Translator,
            ])->id;
        }

        return $data;
    }
}
