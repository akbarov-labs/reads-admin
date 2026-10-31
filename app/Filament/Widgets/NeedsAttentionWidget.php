<?php

namespace App\Filament\Widgets;

use App\Enums\BookProblem;
use App\Enums\InquiryStatus;
use App\Filament\Resources\AnnouncementResource;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\InquiryResource;
use App\Filament\Resources\ReaderReviewResource;
use App\Filament\Resources\TaqrizResource;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Inquiry;
use App\Models\ReaderReview;
use App\Models\Taqriz;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * The admin's dashboard opens on what is waiting for them, each count a link
 * to the list already filtered down to those rows.
 */
class NeedsAttentionWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Needs attention';

    public static function canView(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    protected function getStats(): array
    {
        $pending = ['tableFilters' => ['status' => ['value' => 'pending']]];

        $problems = Book::query();
        BookProblem::applyAny($problems);

        return [
            $this->waiting('Taqrizlar to review', Taqriz::query()->where('status', 'pending')->count())
                ->url(TaqrizResource::getUrl('index', $pending)),
            $this->waiting('Reader reviews to review', ReaderReview::query()->where('status', 'pending')->count())
                ->url(ReaderReviewResource::getUrl('index', $pending)),
            $this->waiting('Announcements to review', Announcement::query()->where('status', 'pending')->count())
                ->url(AnnouncementResource::getUrl('index')),
            $this->waiting('Books added by reviewers', Book::query()->where('status', 'pending')->count())
                ->url(BookResource::getUrl('index', $pending)),
            $this->waiting('New inquiries', Inquiry::query()->where('status', InquiryStatus::New)->count())
                ->url(InquiryResource::getUrl('index', ['tableFilters' => ['status' => ['value' => InquiryStatus::New->value]]])),
            $this->waiting('Books with gaps', $problems->count(), 'No cover, description, author, genre or ISBN')
                ->url(BookResource::getUrl('index', ['tableFilters' => ['needs_attention' => ['value' => 'any']]])),
        ];
    }

    private function waiting(string $label, int $count, ?string $description = null): Stat
    {
        return Stat::make($label, number_format($count))
            ->description($description ?? ($count > 0 ? 'Waiting for you' : 'All clear'))
            ->color($count > 0 ? 'warning' : 'success');
    }
}
