<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AuthorResource;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\PublisherResource;
use App\Filament\Resources\TranslatorResource;
use App\Models\Author;
use App\Models\Book;
use App\Models\Edition;
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
        ];
    }
}
