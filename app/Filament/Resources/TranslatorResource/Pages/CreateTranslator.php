<?php

namespace App\Filament\Resources\TranslatorResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\TranslatorResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateTranslator extends CreateRecord
{
    protected static string $resource = TranslatorResource::class;

    protected array $accountData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->accountData = Arr::only($data, ['email', 'password']);

        return Arr::except($data, ['email', 'password']);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $this->accountData['email'],
            'password' => $this->accountData['password'],
            'role' => UserRole::Translator,
        ]);

        $data['user_id'] = $user->id;

        return static::getModel()::create($data);
    }
}
