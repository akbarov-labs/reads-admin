<?php

namespace App\Http\Resources\Api;

use App\Models\ReaderReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One approved review from a Kitoblarim bot reader. The name is the first
 * name they had in the bot, or null.
 *
 * @mixin ReaderReview
 */
class ReaderReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'authorName' => $this->author_name,
            'rating' => $this->rating,
            'body' => $this->body,
            'writtenAt' => $this->written_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
