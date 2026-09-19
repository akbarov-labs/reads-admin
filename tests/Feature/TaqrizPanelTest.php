<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\TaqrizStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TaqrizResource\Pages\CreateTaqriz;
use App\Filament\Resources\TaqrizResource\Pages\ListTaqrizlar;
use App\Models\Book;
use App\Models\Taqriz;
use App\Models\Taqrizchi;
use App\Models\Translator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    private function book(array $attributes = []): Book
    {
        return Book::create(array_merge([
            'uzbek_title' => 'Chol va dengiz',
            'original_title' => 'The Old Man and the Sea',
            'author' => 'Ernest Hemingway',
            'source_language' => 'en',
            'year' => 1952,
        ], $attributes));
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
        $book = $this->book();

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'book_id' => $book->id,
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
            'book_id' => $this->book()->id,
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
            'book_id' => $this->book()->id,
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
            'book_id' => $this->book()->id,
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
            'book_id' => $this->book()->id,
            'taqrizchi_id' => $mine->id,
            'body' => 'Meniki.',
        ]);
        $other = Taqriz::create([
            'book_id' => $this->book(['uzbek_title' => 'Boshqa'])->id,
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

        $one = Taqriz::create(['book_id' => $this->book()->id, 'taqrizchi_id' => $a->id, 'body' => 'A']);
        $two = Taqriz::create(['book_id' => $this->book(['uzbek_title' => 'X'])->id, 'taqrizchi_id' => $b->id, 'body' => 'B']);

        $this->actingAs($this->admin());

        Livewire::test(ListTaqrizlar::class)->assertCanSeeTableRecords([$one, $two]);
    }

    public function test_approving_a_taqriz_also_publishes_the_book_it_is_about(): void
    {
        $reviewer = $this->reviewer();
        // The book a reviewer added along with the review: still queued.
        $book = $this->book(['status' => ModerationStatus::Pending]);
        $taqriz = Taqriz::create([
            'book_id' => $book->id,
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
    }

    public function test_a_reviewer_gets_one_taqriz_per_book(): void
    {
        $reviewer = $this->reviewer();
        $book = $this->book();

        Taqriz::create(['book_id' => $book->id, 'taqrizchi_id' => $reviewer->id, 'body' => 'Birinchi.']);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm(['book_id' => $book->id, 'body' => 'Ikkinchi.'])
            ->call('create')
            ->assertHasFormErrors(['book_id']);

        $this->assertSame(1, Taqriz::count());
    }

    public function test_the_translation_aspect_is_dropped_for_a_book_with_no_translator(): void
    {
        $reviewer = $this->reviewer();
        $book = $this->book();

        $this->assertNull($book->translator_id);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'book_id' => $book->id,
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

    public function test_the_translation_aspect_is_kept_when_the_book_has_a_translator(): void
    {
        $reviewer = $this->reviewer();
        $book = $this->book(['translator_id' => $this->translator()->id]);

        $this->actingAs($reviewer->user);

        Livewire::test(CreateTaqriz::class)
            ->fillForm([
                'book_id' => $book->id,
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
