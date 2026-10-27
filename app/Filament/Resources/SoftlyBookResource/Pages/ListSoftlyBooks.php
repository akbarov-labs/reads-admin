<?php

namespace App\Filament\Resources\SoftlyBookResource\Pages;

use App\Filament\Resources\SoftlyBookResource;
use App\Models\LibraryImportRow;
use App\Models\SoftlyBook;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListSoftlyBooks extends ListRecords
{
    protected static string $resource = SoftlyBookResource::class;

    public const TO_CHECK = 'check';

    public const WAITING = 'waiting';

    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const SKIPPED = 'skipped';

    public function getSubheading(): ?string
    {
        return 'Books in the kutubxonam.uz libraries that the sync could not place in the catalogue on its own.';
    }

    public function getTabs(): array
    {
        $tabs = [
            self::TO_CHECK => 'To check',
            self::WAITING => 'Nothing like it',
            self::CREATED => 'Made from Softly',
            self::LINKED => 'Linked by you',
            self::SKIPPED => 'Skipped',
        ];

        return collect($tabs)
            ->map(fn (string $label, string $state) => Tab::make($label)
                ->badge(self::countTitles($state))
                ->modifyQueryUsing(fn (Builder $query) => self::scope($query, $state)
                    ->whereIn('id', self::titles($state)->selectRaw('min(id)'))))
            ->all();
    }

    public function getDefaultActiveTab(): string
    {
        return self::TO_CHECK;
    }

    /**
     * One row per title and author in this state: the first of the
     * libraries that list it.
     */
    public static function titles(string $state): Builder
    {
        return self::scope(SoftlyBook::query(), $state)->groupBy('name', 'author');
    }

    public static function countTitles(string $state): int
    {
        return DB::query()->fromSub(self::titles($state)->selectRaw('min(id) as id'), 'titles')->count();
    }

    private static function scope(Builder $query, string $state): Builder
    {
        return match ($state) {
            self::TO_CHECK => $query->whereNull('book_id')->where('skipped', false)->where('match', LibraryImportRow::MATCH_SUGGESTED),
            self::WAITING => $query->whereNull('book_id')->where('skipped', false)->where('match', LibraryImportRow::MATCH_NONE),
            self::CREATED => $query->where('match', SoftlyBook::MATCH_CREATED),
            self::LINKED => $query->whereNotNull('book_id')->where('match', LibraryImportRow::MATCH_MANUAL),
            self::SKIPPED => $query->whereNull('book_id')->where('skipped', true),
        };
    }
}
