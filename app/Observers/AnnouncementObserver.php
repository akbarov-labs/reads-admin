<?php

namespace App\Observers;

use App\Models\Announcement;
use App\Services\SiteRevalidator;

/**
 * Announcements show on the home page and on their publisher's page, both
 * built from collection fetches, so the collection ping covers them.
 */
class AnnouncementObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Announcement $announcement): void
    {
        $this->revalidator->everything();
    }

    public function deleted(Announcement $announcement): void
    {
        $this->revalidator->everything();
    }
}
