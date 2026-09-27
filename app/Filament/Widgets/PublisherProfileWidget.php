<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PublisherResource;
use App\Models\Publisher;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * @see TranslatorProfileWidget — same shape. The Publishers nav entry is
 * scoped to the publisher's own row, but the sidebar is hidden for this role
 * (AdminPanelProvider), so this widget is their route into it.
 */
class PublisherProfileWidget extends Widget
{
    protected static string $view = 'filament.widgets.publisher-profile';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -3;

    // Small, already-authenticated data: render immediately rather than
    // popping in after the rest of the dashboard.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Auth::user()?->isPublisher() && Auth::user()->publisher !== null;
    }

    public function getPublisher(): Publisher
    {
        return Auth::user()->publisher->loadCount('editions');
    }

    public function getEditUrl(): string
    {
        return PublisherResource::getUrl('edit', ['record' => $this->getPublisher()]);
    }
}
