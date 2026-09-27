<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\TaqrizStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TaqrizResource\Pages\CreateTaqriz;
use App\Filament\Resources\TaqrizResource\Pages\ListTaqrizlar;
use App\Models\Edition;
use App\Models\Taqriz;
use App\Models\Taqrizchi;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * Reviews in the admin panel.
 *
 * The rule that matters is that a reviewer writes but does not publish. It is
 * enforced in TaqrizObserver rather than in the form, precisely so that
 * hiding the field is not what protects it — these tests go through the panel
 * the way a reviewer would, and then past it the way a crafted request would.
 */
class TaqrizPanelTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);
    }

    private function reviewer(string $slug = 'nodira', string $name = 'Nodira'): Taqrizchi
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

    /**
     * The edition a reviewer read, of a book with no translator unless
     * credits are given.
     *
     * @param  array<string, mixed>  $book
     * @param  array<string, mixed>  $edition
     */
    private function reviewed(array $book = [], array $edition = [], array $credits = []): Edition
    {
        return $this->catalogueBook($book, $edition, credits: $credits)->editions()->first();
    }

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
            'years_active' => 3,
        ]);
    }

    public function test_a_reviewer_can_write_a_taqriz_and_it_arrives_unpublished(): void
    {
        $reviewer = $this->reviewer();
        $book = $this->reviewed()->book;

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'edition_id' => $book->editions()->first()->id,
                'body' => 'Juda yaxshi kitob.',
                'score_plot' => 8.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $taqriz = Taqriz::first();

        $this->assertNotNull($taqriz);
        // Written as themselves without ever being shown the field.
        $this->assertSame($reviewer->id, $taqriz->taqrizchi_id);
        $this->assertSame(TaqrizStatus::Pending, $taqriz->status);
    }

    public function test_a_reviewer_cannot_publish_their_own_taqriz(): void
    {
        $reviewer = $this->reviewer();
        $taqriz = Taqriz::create([
            'edition_id' => $this->reviewed()->id,
            'taqrizchi_id' => $reviewer->id,
            'body' => 'Matn.',
        ]);

        $this->actingAs($reviewer->user);

        // Straight at the model, bypassing the form that hides the field.
        $taqriz->status = TaqrizStatus::Approved;
        $taqriz->save();

        $this->assertSame(TaqrizStatus::Pending, $taqriz->fresh()->status);
    }

    public function test_editing_a_published_taqriz_returns_it_to_the_queue(): void
    {
        $reviewer = $this->reviewer();
        $taqriz = Taqriz::create([
            'edition_id' => $this->reviewed()->id,
            'taqrizchi_id' => $reviewer->id,
            'body' => 'Matn.',
            'status' => TaqrizStatus::Approved,
        ]);

        $this->actingAs($reviewer->user);

        $taqriz->body = 'Qayta yozilgan matn.';
        $taqriz->save();

        // The text that was approved is not the text that would be live.
        $this->assertSame(TaqrizStatus::Pending, $taqriz->fresh()->status);
    }

    public function test_an_admin_can_publish(): void
    {
        $reviewer = $this->reviewer();
        $taqriz = Taqriz::create([
            'edition_id' => $this->reviewed()->id,
            'taqrizchi_id' => $reviewer->id,
            'body' => 'Matn.',
        ]);

        $this->actingAs($this->admin());

        $taqriz->status = TaqrizStatus::Approved;
        $taqriz->save();

        $this->assertSame(TaqrizStatus::Approved, $taqriz->fresh()->status);
    }

    public function test_a_reviewer_sees_only_their_own_taqrizlar(): void
    {
        $mine = $this->reviewer('nodira', 'Nodira');
        $theirs = $this->reviewer('anvar', 'Anvar');

        $ours = Taqriz::create([
            'edition_id' => $this->reviewed()->id,
            'taqrizchi_id' => $mine->id,
            'body' => 'Meniki.',
        ]);
        $other = Taqriz::create([
            'edition_id' => $this->reviewed(['uzbek_title' => 'Boshqa'])->id,
            'taqrizchi_id' => $theirs->id,
            'body' => 'Boshqasi.',
        ]);

        $this->actingAs($mine->user);

        Livewire::test(ListTaqrizlar::class)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_an_admin_sees_the_whole_queue(): void
    {
        $a = $this->reviewer('nodira', 'Nodira');
        $b = $this->reviewer('anvar', 'Anvar');

        $one = Taqriz::create(['edition_id' => $this->reviewed()->id, 'taqrizchi_id' => $a->id, 'body' => 'A']);
        $two = Taqriz::create(['edition_id' => $this->reviewed(['uzbek_title' => 'X'])->id, 'taqrizchi_id' => $b->id, 'body' => 'B']);

        $this->actingAs($this->admin());

        Livewire::test(ListTaqrizlar::class)->assertCanSeeTableRecords([$one, $two]);
    }

    public function test_approving_a_taqriz_also_publishes_the_book_it_is_about(): void
    {
        $reviewer = $this->reviewer();
        // The book and edition a reviewer added along with the review: still queued.
        $edition = $this->reviewed(['status' => ModerationStatus::Pending], ['status' => ModerationStatus::Pending]);
        $book = $edition->book;
        $taqriz = Taqriz::create([
            'edition_id' => $edition->id,
            'taqrizchi_id' => $reviewer->id,
            'body' => 'Matn.',
        ]);

        $this->actingAs($this->admin());

        Livewire::test(ListTaqrizlar::class)
            ->callTableAction('approve', $taqriz);

        // Publishing a review onto a book the site refuses to serve would
        // put it on a 404.
        $this->assertSame(TaqrizStatus::Approved, $taqriz->fresh()->status);
        $this->assertSame(ModerationStatus::Approved, $book->fresh()->status);
        $this->assertSame(ModerationStatus::Approved, $edition->fresh()->status);
    }

    public function test_a_reviewer_gets_one_taqriz_per_edition(): void
    {
        $reviewer = $this->reviewer();
        $edition = $this->reviewed();

        Taqriz::create(['edition_id' => $edition->id, 'taqrizchi_id' => $reviewer->id, 'body' => 'Birinchi.']);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm(['edition_id' => $edition->id, 'body' => 'Ikkinchi.'])
            ->call('create')
            ->assertHasFormErrors(['edition_id']);

        $this->assertSame(1, Taqriz::count());
    }

    public function test_another_translation_of_the_same_book_can_be_reviewed_separately(): void
    {
        $reviewer = $this->reviewer();
        $first = $this->reviewed();
        $second = $this->edition($first->book, ['year' => 2024]);

        Taqriz::create(['edition_id' => $first->id, 'taqrizchi_id' => $reviewer->id, 'body' => 'Birinchi.']);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm(['edition_id' => $second->id, 'body' => 'Ikkinchisi boshqa tarjima.'])
            ->call('create')
            ->assertHasNoFormErrors();

        // Both show on the book's page.
        $this->assertSame(2, $first->book->taqrizlar()->count());
    }

    public function test_the_translation_aspect_is_dropped_for_an_edition_with_no_translator(): void
    {
        $reviewer = $this->reviewer();
        $edition = $this->reviewed();

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'edition_id' => $edition->id,
                'body' => 'Matn.',
                'score_plot' => 8.0,
                'score_translation' => 9.0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Nothing to rate, so nothing is stored — and the average must not
        // be diluted by an answer to a question that was never asked.
        $taqriz = Taqriz::first();
        $this->assertNull($taqriz->score_translation);
        $this->assertSame(8.0, $taqriz->average_score);
    }

    public function test_the_translation_aspect_is_kept_when_the_edition_has_a_translator(): void
    {
        $reviewer = $this->reviewer();
        $edition = $this->reviewed(credits: [$this->translator()]);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'edition_id' => $edition->id,
                'body' => 'Matn.',
                'score_plot' => 8.0,
                'score_translation' => 9.0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $taqriz = Taqriz::first();
        $this->assertSame(9.0, $taqriz->score_translation);
        $this->assertSame(8.5, $taqriz->average_score);
    }
}
