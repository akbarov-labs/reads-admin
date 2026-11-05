<?php

namespace App\Observers;

use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Services\AnnouncementBook;
use App\Services\SiteRevalidator;

/**
 * Announcements show on the home page and on their publisher's page, both
 * built from collection fetches, so the collection ping covers them.
 */
class AnnouncementObserver
{
    public function __construct(
        private readonly SiteRevalidator $revalidator,
        private readonly AnnouncementBook $books,
    ) {}

    /**
     * Publishing an announcement puts its book in the catalogue, unless an
     * admin linked one already. Only the moment of publishing does it: an
     * announcement saved as approved from the start is left as it is.
     */
    public function saving(Announcement $announcement): void
    {
        if ($announcement->exists
            && $announcement->isDirty('status')
            && $announcement->status === ModerationStatus::Approved
            && $announcement->book_id === null) {
            $announcement->book_id = $this->books->ensure($announcement)->id;
        }
    }

    public function saved(Announcement $announcement): void
    {
        $this->revalidator->everything();
    }

    public function deleted(Announcement $announcement): void
    {
        $this->revalidator->everything();
    }
}
