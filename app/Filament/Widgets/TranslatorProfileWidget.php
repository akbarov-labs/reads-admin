<?php

namespace App\Filament\Widgets;

use App\Enums\InquiryStatus;
use App\Filament\Resources\InquiryResource;
use App\Filament\Resources\TranslatorResource;
use App\Models\Translator;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class TranslatorProfileWidget extends Widget
{
    protected static string $view = 'filament.widgets.translator-profile';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -3;

    // Small, already-authenticated data — render immediately instead of the
    // default lazy (AJAX-after-mount) behaviour, so it doesn't visibly pop
    // in a moment after the rest of the dashboard.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Auth::user()?->isTranslator() && Auth::user()->translator !== null;
    }

    public function getTranslator(): Translator
    {
        return Auth::user()->translator->loadCount(['books', 'languagePairs']);
    }

    public function getEditUrl(): string
    {
        return TranslatorResource::getUrl('edit', ['record' => $this->getTranslator()]);
    }

    public function getNewInquiriesCount(): int
    {
        return $this->getTranslator()->inquiries()->where('status', InquiryStatus::New)->count();
    }

    public function getInquiriesUrl(): string
    {
        return InquiryResource::getUrl();
    }
}
