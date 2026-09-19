<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\TaqrizchiResource\Pages\ListTaqrizchilar;
use App\Models\Taqrizchi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reviewer profiles in the admin panel. The interesting part is the same one
 * as for translators: the list is scoped so a reviewer cannot browse other
 * people's rows, and the fields that decide identity and public URL are
 * admin-only in the model layer rather than only in the form.
 */
class TaqrizchiPanelTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(string $slug, string $name): Taqrizchi
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$slug}@example.test",
            'password' => 'password',
            'role' => UserRole::Taqrizchi,
        ]);

        return Taqrizchi::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'name' => $name,
        ]);
    }

    public function test_a_reviewer_sees_only_their_own_profile(): void
    {
        $mine = $this->reviewer('nodira', 'Nodira');
        $theirs = $this->reviewer('anvar', 'Anvar');

        $this->actingAs($mine->user);

        Livewire::test(ListTaqrizchilar::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_an_admin_sees_every_profile(): void
    {
        $a = $this->reviewer('nodira', 'Nodira');
        $b = $this->reviewer('anvar', 'Anvar');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListTaqrizchilar::class)->assertCanSeeTableRecords([$a, $b]);
    }

    public function test_a_reviewer_cannot_move_their_own_public_url(): void
    {
        $reviewer = $this->reviewer('nodira', 'Nodira');

        $this->actingAs($reviewer->user);

        // Past the form, which disables the field, straight at the model.
        $reviewer->slug = 'something-else';
        $reviewer->bio = 'Yangi matn.';
        $reviewer->save();

        $fresh = $reviewer->fresh();

        // The slug is pinned, but the legitimate half of the save goes
        // through rather than the whole write being rejected.
        $this->assertSame('nodira', $fresh->slug);
        $this->assertSame('Yangi matn.', $fresh->bio);
    }

    public function test_an_admin_can_move_a_public_url(): void
    {
        $reviewer = $this->reviewer('nodira', 'Nodira');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        $reviewer->slug = 'nodira-karimova';
        $reviewer->save();

        $this->assertSame('nodira-karimova', $reviewer->fresh()->slug);
    }

    /**
     * The dashboard is a reviewer's only route to their own profile, since
     * the Taqrizchilar nav entry is admin-only. This renders the Blade view,
     * which the Livewire tests above never touch.
     */
    public function test_the_dashboard_renders_the_reviewer_widget(): void
    {
        $reviewer = $this->reviewer('nodira', 'Nodira');

        $this->actingAs($reviewer->user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Write a taqriz')
            ->assertSee('Edit my profile');
    }

}
