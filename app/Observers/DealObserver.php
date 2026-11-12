<?php

namespace App\Observers;

use App\Enums\ModerationStatus;
use App\Models\Deal;
use App\Services\SiteRevalidator;

/**
 * Deals show on the site's Chegirmalar page, the home page and their
 * publisher's page, all collection fetches: the collection ping covers them.
 * A draft the scan saves is not on the site, so it pings nothing.
 */
class DealObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Deal $deal): void
    {
        if ($deal->status === ModerationStatus::Approved || $deal->wasChanged('status')) {
            $this->revalidator->everything();
        }
    }

    public function deleted(Deal $deal): void
    {
        if ($deal->status === ModerationStatus::Approved) {
            $this->revalidator->everything();
        }
    }
}
