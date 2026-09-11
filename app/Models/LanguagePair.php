<?php

namespace App\Models;

use App\Observers\ProfileContentObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ProfileContentObserver::class)]
#[Fillable(['translator_id', 'from_language', 'to_language', 'sort_order'])]
class LanguagePair extends Model
{
    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }
}
