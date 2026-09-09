<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('reads:create-admin
    {--name= : Display name}
    {--email= : Email used to sign in}
    {--password= : Password. Omit it to be prompted securely instead of putting it in shell history}
    {--promote : Promote an existing account to admin rather than failing}')]
#[Description('Create a Filament admin account with access to every translator')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');

        // Look the account up before asking for anything else: promoting an
        // existing user needs only --email --promote, and prompting for a
        // name first would stall that on a machine with no TTY.
        //
        // `make:filament-user` exists but is not role-aware: it would leave
        // the users.role column on its default ("translator"), producing an
        // account that can sign in but only sees its own profile. Hence
        // this command.
        if ($existing = User::where('email', $email)->first()) {
            return $this->handleExisting($existing);
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $password = $this->option('password') ?: $this->promptForPassword();

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // 'password' is cast to 'hashed' on the User model, so the plain
        // value is hashed on save.
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
        ]);

        $this->info("Admin account created for {$email}.");
        $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }

    private function handleExisting(User $user): int
    {
        if ($user->isAdmin()) {
            $this->info("{$user->email} is already an admin. Nothing to do.");

            return self::SUCCESS;
        }

        $shouldPromote = $this->option('promote') || $this->confirm(
            "{$user->email} already exists as a translator account. Promote it to admin?",
        );

        if (! $shouldPromote) {
            $this->error('Aborted. Use a different email, or pass --promote.');

            return self::FAILURE;
        }

        $user->update(['role' => UserRole::Admin]);

        $this->info("Promoted {$user->email} to admin.");

        if ($user->translator) {
            $this->warn(
                "Note: this account still owns the translator profile \"{$user->translator->name}\", "
                .'which stays published. Delete it from the admin panel if that is not intended.',
            );
        }

        return self::SUCCESS;
    }

    private function promptForPassword(): string
    {
        while (true) {
            $password = (string) $this->secret('Password (min 8 characters)');

            if ($password === (string) $this->secret('Confirm password')) {
                return $password;
            }

            $this->error('Passwords did not match. Try again.');
        }
    }
}
