<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AuthorResource;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\LibraryResource;
use App\Filament\Resources\PublisherResource;
use App\Filament\Resources\TranslatorResource;
use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
use App\Models\Library;
use App\Models\Publisher;
use App\Models\Translator;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** How big the catalogue is, under Needs attention on the admin's dashboard. */
class CatalogueOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Catalogue';

    public static function canView(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Books', number_format(Book::query()->approved()->count()))
                ->description(number_format(Edition::query()->approved()->count()).' editions')
                ->url(BookResource::getUrl('index')),
            Stat::make('Authors', number_format(Author::query()->count()))
                ->url(AuthorResource::getUrl('index')),
            Stat::make('Translators', number_format(Translator::query()->count()))
                ->description(number_format(Translator::query()->whereNotNull('user_id')->count()).' with a login')
                ->url(TranslatorResource::getUrl('index')),
            Stat::make('Publishers', number_format(Publisher::query()->count()))
                ->description(number_format(Publisher::query()->whereNotNull('user_id')->count()).' with a login')
                ->url(PublisherResource::getUrl('index')),
            $this->libraries(),
        ];
    }

    /** Libraries on the site, and how many owe readers a fresh list. */
    private function libraries(): Stat
    {
        $published = Library::query()->published();
        $stale = (clone $published)->where(fn ($library) => $library->whereNull('holdings_updated_at')
            ->orWhere('holdings_updated_at', '<', now()->subDays(Library::STALE_AFTER_DAYS)))
            ->count();

        return Stat::make('Libraries', number_format($published->count()))
            ->description($stale > 0
                ? "{$stale} with a list over ".Library::STALE_AFTER_DAYS.' days old'
                : number_format(Library::query()->count()).' in all')
            ->color($stale > 0 ? 'warning' : null)
            ->url(LibraryResource::getUrl('index'));
    }
}
