<?php

namespace App\Filament\Resources\TranslatorResource\Pages;

use App\Filament\Resources\TranslatorResource;
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
        $data['email'] = $this->record->user->email;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $email = Arr::pull($data, 'email');
        $password = Arr::pull($data, 'password');

        $updates = array_filter(
            ['email' => $email, 'password' => $password],
            fn ($value) => filled($value),
        );

        if ($updates !== []) {
            $this->record->user->update($updates);
        }

        return $data;
    }
}
