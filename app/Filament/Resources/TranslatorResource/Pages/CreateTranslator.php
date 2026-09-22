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

    /**
     * The login is optional here for the same reason it is on
     * PublisherResource\Pages\CreatePublisher: a translator can now exist as
     * a name-only row — most commonly one a publisher named inline while
     * adding a book — before anyone is ready to sign in as them.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $email = $this->accountData['email'] ?? null;

        if (filled($email)) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $this->accountData['password'] ?? null,
                'role' => UserRole::Translator,
            ]);

            $data['user_id'] = $user->id;
        }

        return static::getModel()::create($data);
    }
}
