<?php

namespace App\Observers;

use App\Enums\ModerationStatus;
use App\Models\ReaderReview;
use App\Services\SiteRevalidator;

/**
 * A moderator's approve, reject or delete changes what the book's page shows.
 * The sync writes without events and pings the site itself, once per book.
 */
class ReaderReviewObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(ReaderReview $review): void
    {
        $was = $review->getOriginal('status');
        $wasApproved = ($was instanceof ModerationStatus ? $was : ModerationStatus::tryFrom((string) $was)) === ModerationStatus::Approved;

        if ($wasApproved || $review->status === ModerationStatus::Approved) {
            $this->revalidator->book($review->book_id);
        }
    }

    public function deleted(ReaderReview $review): void
    {
        if ($review->status === ModerationStatus::Approved) {
            $this->revalidator->book($review->book_id);
        }
    }
}
