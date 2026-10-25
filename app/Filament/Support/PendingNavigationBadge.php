<?php

namespace App\Filament\Support;

/**
 * The sidebar count of rows waiting for an admin's decision: taqrizlar,
 * reader reviews, announcements and books a reviewer added. Every one of
 * them keeps the decision in a `status` column whose waiting value is
 * "pending" (TaqrizStatus and ModerationStatus agree on it).
 *
 * Admins only. A taqrizchi also sees the Taqrizlar entry, and a count of
 * everybody's pending reviews is not theirs to see.
 */
trait PendingNavigationBadge
{
    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->isAdmin()) {
            return null;
        }

        $count = static::getModel()::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for review';
    }
}
