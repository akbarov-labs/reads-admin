<?php

namespace App\Filament\Widgets;

use App\Enums\TaqrizStatus;
use App\Filament\Resources\TaqrizchiResource;
use App\Filament\Resources\TaqrizResource;
use App\Models\Taqrizchi;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * @see TranslatorProfileWidget — the reviewer's way into their own profile.
 * The Taqrizchilar nav entry is admin-only, so without this a reviewer would
 * have no route to edit their own page.
 */
class TaqrizchiProfileWidget extends Widget
{
    protected static string $view = 'filament.widgets.taqrizchi-profile';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -3;

    // Small, already-authenticated data: render immediately rather than
    // popping in after the rest of the dashboard.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Auth::user()?->isTaqrizchi() && Auth::user()->taqrizchi !== null;
    }

    public function getTaqrizchi(): Taqrizchi
    {
        return Auth::user()->taqrizchi->loadCount('taqrizlar');
    }

    public function getEditUrl(): string
    {
        return TaqrizchiResource::getUrl('edit', ['record' => $this->getTaqrizchi()]);
    }

    public function getTaqrizlarUrl(): string
    {
        return TaqrizResource::getUrl();
    }

    public function getWriteUrl(): string
    {
        return TaqrizResource::getUrl('create');
    }

    /** Reviews of theirs still waiting on an admin. */
    public function getPendingCount(): int
    {
        return $this->getTaqrizchi()->taqrizlar()->where('status', TaqrizStatus::Pending)->count();
    }

    public function getPublishedCount(): int
    {
        return $this->getTaqrizchi()->taqrizlar()->approved()->count();
    }
}
