<?php

namespace App\Filament\Resources\TaqrizchiResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\TaqrizchiResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @see CreateTranslator — the profile and its login account are created
 * together, so an admin never has to make a user first and remember to link
 * it afterwards.
 */
class CreateTaqrizchi extends CreateRecord
{
    protected static string $resource = TaqrizchiResource::class;

    /** @var array<string, mixed> */
    protected array $accountData = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->accountData = Arr::only($data, ['email', 'password']);

        return Arr::except($data, ['email', 'password']);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $this->accountData['email'],
            'password' => $this->accountData['password'],
            'role' => UserRole::Taqrizchi,
        ]);

        $data['user_id'] = $user->id;

        return static::getModel()::create($data);
    }
}
