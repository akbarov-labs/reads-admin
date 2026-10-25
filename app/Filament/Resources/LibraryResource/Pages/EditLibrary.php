<?php

namespace App\Filament\Resources\LibraryResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\LibraryResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ContentTabPosition;
use Filament\Resources\Pages\EditRecord;

/** @see \App\Filament\Resources\PublisherResource\Pages\EditPublisher */
class EditLibrary extends EditRecord
{
    protected static string $resource = LibraryResource::class;

    /**
     * The books and the page as two tabs, books first and open: lending a
     * copy out and taking it back is what an owner comes here for most days.
     */
    public function mount(int | string $record): void
    {
        parent::mount($record);

        $this->activeRelationManager ??= '0';
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabPosition(): ?ContentTabPosition
    {
        return ContentTabPosition::After;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Library page';
    }

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-m-building-library';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import books from Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->url(fn () => LibraryResource::getUrl('import', ['record' => $this->record])),
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
     * Only an admin can attach or change the login. An owner never sees
     * these fields, and LibraryObserver pins user_id anyway.
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
            $user->fill(array_filter(['email' => $email, 'password' => $password]));
            $user->save();
        } elseif (filled($email)) {
            $data['user_id'] = User::create([
                'name' => $this->record->name,
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Library,
            ])->id;
        }

        return $data;
    }
}
