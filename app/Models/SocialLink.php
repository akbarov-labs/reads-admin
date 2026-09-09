<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['translator_id', 'platform', 'url', 'sort_order'])]
class SocialLink extends Model
{
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
        ];
    }

    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }
}
