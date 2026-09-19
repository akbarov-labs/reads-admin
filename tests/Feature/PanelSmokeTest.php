<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Publisher;
use App\Models\Taqrizchi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every new panel screen, requested the way a browser does. */
class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_every_new_screen(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        foreach ([
            '/admin',
            '/admin/taqrizchilar', '/admin/taqrizchilar/create',
            '/admin/taqrizlar', '/admin/taqrizlar/create',
            '/admin/publishers', '/admin/publishers/create',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_reviewer_can_open_the_screens_they_own(): void
    {
        $user = User::create([
            'name' => 'Nodira', 'email' => 'n@example.test',
            'password' => 'password', 'role' => UserRole::Taqrizchi,
        ]);
        $profile = Taqrizchi::create(['user_id' => $user->id, 'slug' => 'nodira', 'name' => 'Nodira']);

        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->get('/admin/taqrizlar')->assertOk();
        $this->get('/admin/taqrizlar/create')->assertOk();
        $this->get("/admin/taqrizchilar/{$profile->getRouteKey()}/edit")->assertOk();
    }

    public function test_a_publisher_can_open_their_own_entry(): void
    {
        $user = User::create([
            'name' => 'Yangi asr', 'email' => 'p@example.test',
            'password' => 'password', 'role' => UserRole::Publisher,
        ]);
        $publisher = Publisher::create(['user_id' => $user->id, 'slug' => 'yangi-asr', 'name' => 'Yangi asr']);

        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->get('/admin/publishers')->assertOk();
        $this->get("/admin/publishers/{$publisher->getRouteKey()}/edit")->assertOk();
    }
}
