<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function translator(): HasOne
    {
        return $this->hasOne(Translator::class);
    }

    public function taqrizchi(): HasOne
    {
        return $this->hasOne(Taqrizchi::class);
    }

    public function publisher(): HasOne
    {
        return $this->hasOne(Publisher::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isTranslator(): bool
    {
        return $this->role === UserRole::Translator;
    }

    public function isTaqrizchi(): bool
    {
        return $this->role === UserRole::Taqrizchi;
    }

    public function isPublisher(): bool
    {
        return $this->role === UserRole::Publisher;
    }

    /**
     * Gate for the whole admin panel. `users.role` defaults to "translator",
     * so any row that ends up in the table — created by a future signup
     * route, an import, a seeder — would otherwise be able to sign in. A
     * translator earns access by having a profile to manage; an admin always
     * has access.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isTranslator()) {
            return $this->translator()->exists();
        }

        if ($this->isTaqrizchi()) {
            return $this->taqrizchi()->exists();
        }

        return $this->isPublisher() && $this->publisher()->exists();
    }
}
