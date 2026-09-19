<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Taqrizchi;
use App\Models\Translator;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function panel()
    {
        return Filament::getPanel('admin');
    }

    public function test_an_admin_can_access_the_panel(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $this->assertTrue($admin->canAccessPanel($this->panel()));
    }

    public function test_a_translator_with_a_profile_can_access_the_panel(): void
    {
        $user = User::create([
            'name' => 'Ilhom',
            'email' => 'ilhom@example.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        Translator::create([
            'user_id' => $user->id,
            'slug' => 'ilhom',
            'name' => 'Ilhom',
            'years_active' => 1,
        ]);

        $this->assertTrue($user->fresh()->canAccessPanel($this->panel()));
    }

    /**
     * users.role defaults to "translator", so any row created outside the
     * admin's own "add translator" flow lands in this state. It has nothing
     * to manage, and letting it sign in would put an unattached account
     * inside the panel.
     */
    public function test_a_user_without_a_translator_profile_cannot_access_the_panel(): void
    {
        $user = User::create([
            'name' => 'Stray account',
            'email' => 'stray@example.test',
            'password' => 'password',
        ]);

        // fresh() because the "translator" role comes from the column
        // default, which the in-memory instance has not seen yet.
        $stored = $user->fresh();

        $this->assertSame(UserRole::Translator, $stored->role);
        $this->assertFalse($stored->canAccessPanel($this->panel()));
    }

    public function test_a_taqrizchi_with_a_profile_can_access_the_panel(): void
    {
        $user = User::create([
            'name' => 'Nodira',
            'email' => 'nodira@example.test',
            'password' => 'password',
            'role' => UserRole::Taqrizchi,
        ]);

        Taqrizchi::create([
            'user_id' => $user->id,
            'slug' => 'nodira',
            'name' => 'Nodira',
        ]);

        $this->assertTrue($user->fresh()->canAccessPanel($this->panel()));
    }

    /**
     * Same reasoning as the translator case above: a reviewer earns access by
     * having a profile to manage, not by carrying the role.
     */
    public function test_a_taqrizchi_without_a_profile_cannot_access_the_panel(): void
    {
        $user = User::create([
            'name' => 'Nobody',
            'email' => 'nobody@example.test',
            'password' => 'password',
            'role' => UserRole::Taqrizchi,
        ]);

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

}
