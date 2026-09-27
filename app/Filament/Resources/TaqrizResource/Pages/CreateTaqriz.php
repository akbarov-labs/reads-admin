<?php

namespace App\Filament\Resources\TaqrizResource\Pages;

use App\Filament\Resources\TaqrizResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateTaqriz extends CreateRecord
{
    protected static string $resource = TaqrizResource::class;

    /**
     * A reviewer never chooses whose name goes on the review — the field is
     * not even rendered for them, so it is filled in here from the signed-in
     * account. An admin writing on someone's behalf picks it on the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! $user?->isAdmin()) {
            $data['taqrizchi_id'] = $user?->taqrizchi?->id;
        }

        if (blank($data['taqrizchi_id'] ?? null)) {
            throw ValidationException::withMessages([
                'data.taqrizchi_id' => 'This account has no taqrizchi profile, so it cannot write a taqriz.',
            ]);
        }

        // One review per edition per reviewer. The unique index is the real
        // guarantee; this turns the resulting 500 into a sentence.
        $exists = static::getModel()::query()
            ->where('taqrizchi_id', $data['taqrizchi_id'])
            ->where('edition_id', $data['edition_id'] ?? null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'data.edition_id' => 'You have already reviewed this edition. Edit the existing taqriz instead.',
            ]);
        }

        return $data;
    }
}
