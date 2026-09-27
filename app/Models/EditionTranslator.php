<?php

namespace App\Models;

use App\Enums\BookRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** The credit line: which translator did what on which edition. */
class EditionTranslator extends Pivot
{
    protected $table = 'edition_translator';

    public $incrementing = true;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['edition_id', 'translator_id', 'role', 'position'];

    /** @return BelongsTo<Translator, $this> */
    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }

    /** @return BelongsTo<Edition, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    protected function casts(): array
    {
        return [
            'role' => BookRole::class,
            'position' => 'integer',
        ];
    }
}
