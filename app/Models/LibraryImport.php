<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Excel or CSV list a library uploaded; see LibraryImporter. */
#[Fillable(['library_id', 'user_id', 'filename', 'status', 'applied_at'])]
class LibraryImport extends Model
{
    public const REVIEW = 'review';

    public const APPLIED = 'applied';

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** @return HasMany<LibraryImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(LibraryImportRow::class);
    }

    /** @return array{total: int, accepted: int, pending: int, skipped: int} */
    public function counts(): array
    {
        $byDecision = $this->rows()->toBase()->selectRaw('decision, count(*) as n')->groupBy('decision')->pluck('n', 'decision');

        return [
            'total' => (int) $byDecision->sum(),
            'accepted' => (int) ($byDecision[LibraryImportRow::ACCEPT] ?? 0),
            'pending' => (int) ($byDecision[LibraryImportRow::PENDING] ?? 0),
            'skipped' => (int) ($byDecision[LibraryImportRow::SKIP] ?? 0),
        ];
    }
}
