<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['translator_id', 'from_language', 'to_language', 'sort_order'])]
class LanguagePair extends Model
{
    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }
}
