<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Services\PublisherSuggestions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a search of the web and Telegram found for a publisher, waiting for
 * an admin: published, it fills the publisher's page; rejected, it stays
 * here so loading the file again does not bring it back.
 *
 * @see PublisherSuggestions
 */
#[Fillable([
    'publisher_id',
    'telegram_channel',
    'subscribers',
    'order_contact',
    'shop_url',
    'website_url',
    'phone',
    'instagram',
    'address',
    'established_year',
    'bio',
    'motto',
    'about_sources',
    'logo_path',
    'confidence',
    'evidence',
    'other_channels',
    'note',
    'status',
    'reviewed_at',
])]
class PublisherSuggestion extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'bio' => 'array',
            'motto' => 'array',
            'about_sources' => 'array',
            'subscribers' => 'integer',
            'established_year' => 'integer',
            'status' => ModerationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /**
     * Another publisher that already has this channel: two rows for one
     * house, usually, which a merge fixes better than a second channel.
     */
    public function channelOwner(): ?Publisher
    {
        if ($this->telegram_channel === null) {
            return null;
        }

        return Publisher::query()
            ->whereKeyNot($this->publisher_id)
            ->whereRaw('lower(telegram_channel) = ?', [strtolower($this->telegram_channel)])
            ->first();
    }

    public function channelUrl(): ?string
    {
        return $this->telegram_channel ? "https://t.me/{$this->telegram_channel}" : null;
    }
}
