<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LibraryResource;
use App\Models\Library;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * @see PublisherProfileWidget — same shape. A library owner's sidebar is
 * hidden (AdminPanelProvider), so this is their way to their books.
 */
class LibraryProfileWidget extends Widget
{
    protected static string $view = 'filament.widgets.library-profile';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Auth::user()?->isLibrary() && Auth::user()->library !== null;
    }

    public function getLibrary(): Library
    {
        return Auth::user()->library
            ->loadCount([
                'holdings',
                'holdings as lent_out_count' => fn ($holdings) => $holdings->where('copies_available', 0),
            ]);
    }

    public function getEditUrl(): string
    {
        return LibraryResource::getUrl('edit', ['record' => $this->getLibrary()]);
    }

    public function isStale(): bool
    {
        return LibraryResource::isStale($this->getLibrary());
    }
}
