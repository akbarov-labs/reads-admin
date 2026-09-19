<?php

namespace App\Filament\Resources\PublisherResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\PublisherResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreatePublisher extends CreateRecord
{
    protected static string $resource = PublisherResource::class;

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

    /**
     * The login is optional here, unlike for translators and taqrizchilar: a
     * publisher row is a catalogue entry first and an account second, and
     * most of them were backfilled from the old free-text column with nobody
     * to sign in as.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $email = $this->accountData['email'] ?? null;

        if (filled($email)) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $this->accountData['password'] ?? null,
                'role' => UserRole::Publisher,
            ]);

            $data['user_id'] = $user->id;
        }

        return static::getModel()::create($data);
    }
}
