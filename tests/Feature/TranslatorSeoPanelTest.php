<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\TranslatorResource\Pages\EditTranslator;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SEO section is admin-only. TranslatorSeoTest proves the server-side
 * guard; this proves the panel itself never puts the fields in front of a
 * translator in the first place — which is what stops an honest translator
 * from wondering why their edits keep disappearing.
 */
class TranslatorSeoPanelTest extends TestCase
{
    use RefreshDatabase;

    private function translator(): Translator
    {
        $user = User::create([
            'name' => 'Ilhom',
            'email' => 'ilhom@example.test',
            'password' => 'password',
            'role' => UserRole::Translator,
        ]);

        return Translator::create([
            'user_id' => $user->id,
            'slug' => 'ilhom',
            'name' => 'Ilhom',
            'years_active' => 1,
        ]);
    }

    public function test_an_admin_sees_the_seo_fields(): void
    {
        $translator = $this->translator();

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditTranslator::class, ['record' => $translator->getRouteKey()])
            ->assertFormFieldExists('meta_title.uz')
            ->assertFormFieldExists('meta_description.en')
            ->assertFormFieldExists('seo_noindex')
            ->assertFormFieldExists('og_image_path');
    }

    public function test_a_translator_does_not_see_the_seo_fields(): void
    {
        $translator = $this->translator();

        $this->actingAs($translator->user);

        Livewire::test(EditTranslator::class, ['record' => $translator->getRouteKey()])
            ->assertFormFieldDoesNotExist('meta_title.uz')
            ->assertFormFieldDoesNotExist('meta_description.en')
            ->assertFormFieldDoesNotExist('seo_noindex')
            ->assertFormFieldDoesNotExist('og_image_path')
            // The login-account block is admin-only for the same reason.
            ->assertFormFieldDoesNotExist('email')
            // ...but their own profile fields are still editable.
            ->assertFormFieldExists('bio.uz');
    }
}
