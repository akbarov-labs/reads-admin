<?php

namespace App\Observers;

use App\Enums\TaqrizStatus;
use App\Models\Taqriz;
use App\Services\SiteRevalidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TaqrizObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    /**
     * Moderation is the whole point of the status column, so this is where it
     * is actually enforced rather than in the form that happens to render it.
     * A reviewer can write and revise; only an admin decides what is
     * published. Editing an approved taqriz sends it back for review — the
     * text that was approved is not the text that would then be live.
     */
    public function saving(Taqriz $taqriz): void
    {
        $user = Auth::user();

        // Seeders, console commands and tests are trusted contexts.
        if ($user === null || $user->isAdmin()) {
            return;
        }

        if (! $taqriz->isDirty('status')) {
            // An edit to a published taqriz un-publishes it.
            if ($taqriz->exists && $taqriz->isDirty() && $taqriz->status === TaqrizStatus::Approved) {
                $taqriz->status = TaqrizStatus::Pending;
            }

            return;
        }

        Log::warning('Blocked a non-admin write to a taqriz status.', [
            'user_id' => $user->id,
            'taqriz_id' => $taqriz->id,
            'attempted' => $taqriz->status?->value,
        ]);

        $taqriz->status = $taqriz->exists
            ? TaqrizStatus::from($taqriz->getRawOriginal('status'))
            : TaqrizStatus::Pending;
    }

    public function saved(Taqriz $taqriz): void
    {
        $this->revalidate($taqriz);
    }

    public function deleted(Taqriz $taqriz): void
    {
        $this->revalidate($taqriz);
    }

    /**
     * A taqriz shows on the book's page and on its author's profile, so both
     * go. Pending and rejected reviews are invisible to the public site, but
     * the transition into or out of that state is exactly what changes the
     * page — so this fires regardless of status.
     */
    private function revalidate(Taqriz $taqriz): void
    {
        $this->revalidator->book($taqriz->edition?->book_id);
        $this->revalidator->taqrizchi($taqriz->taqrizchi?->slug);
    }
}
