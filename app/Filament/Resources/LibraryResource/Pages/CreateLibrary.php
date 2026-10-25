<?php

namespace App\Filament\Resources\LibraryResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\LibraryResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** @see \App\Filament\Resources\PublisherResource\Pages\CreatePublisher */
class CreateLibrary extends CreateRecord
{
    protected static string $resource = LibraryResource::class;

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
        $email = $this->accountData['email'] ?? null;

        if (filled($email)) {
            $data['user_id'] = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $this->accountData['password'] ?? null,
                'role' => UserRole::Library,
            ])->id;
        }

        return static::getModel()::create($data);
    }
}
