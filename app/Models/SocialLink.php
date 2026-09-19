<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Observers\ProfileContentObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[ObservedBy(ProfileContentObserver::class)]
#[Fillable(['owner_type', 'owner_id', 'platform', 'url', 'sort_order'])]
class SocialLink extends Model
{
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
        ];
    }

    /**
     * A translator or a taqrizchi. Both have public profiles that show the
     * same row of links.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
