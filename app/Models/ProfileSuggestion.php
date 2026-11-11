<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Services\ProfileSuggestions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What research found about an author or a translator: a bio by locale and
 * the facts beside it, with the pages it came from. Published, it fills the
 * person's page; rejected, it stays so loading the file again does not
 * bring it back.
 *
 * @property-read Author|Translator $subject
 *
 * @see ProfileSuggestions
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'original_name',
    'birth_year',
    'death_year',
    'nationality',
    'title',
    'location',
    'bio',
    'sources',
    'confidence',
    'note',
    'status',
    'reviewed_at',
])]
class ProfileSuggestion extends Model
{
    /** The kinds of page a suggestion can fill, by the name the file uses. */
    public const TYPES = ['author' => Author::class, 'translator' => Translator::class];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'death_year' => 'integer',
            'nationality' => 'array',
            'title' => 'array',
            'location' => 'array',
            'bio' => 'array',
            'sources' => 'array',
            'status' => ModerationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isAuthor(): bool
    {
        return $this->subject_type === Author::class;
    }

    /** "author" or "translator". */
    public function kind(): string
    {
        return (string) array_search($this->subject_type, self::TYPES, true);
    }
}
