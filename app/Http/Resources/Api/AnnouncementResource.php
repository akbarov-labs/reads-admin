<?php

namespace App\Http\Resources\Api;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One announcement as the site shows it: the book, its prices, the
 * publisher, and where the order button leads (`orderContact`, a Telegram
 * username; null sends the reader to the post itself).
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'author' => $this->author_name,
            'translator' => $this->translator_name,
            'isPreorder' => $this->isPreorder(),
            'price' => $this->price,
            'preorderPrice' => $this->preorder_price,
            'preorderDeadline' => $this->preorder_deadline?->toDateString(),
            'coverUrl' => $this->coverUrl(),
            'postedAt' => $this->posted_at?->toIso8601String(),
            'sourceUrl' => $this->source_url,
            'orderContact' => $this->publisher->order_contact,
            'publisher' => [
                'slug' => $this->publisher->slug,
                'name' => $this->publisher->name,
            ],
            'bookId' => $this->book_id !== null ? (string) $this->book_id : null,
        ];
    }
}
