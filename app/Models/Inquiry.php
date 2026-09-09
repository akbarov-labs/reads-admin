<?php

namespace App\Models;

use App\Enums\BudgetRange;
use App\Enums\InquiryStatus;
use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'translator_id',
    'name',
    'email',
    'from_language',
    'to_language',
    'project_type',
    'word_count',
    'budget_range',
    'deadline',
    'message',
    'status',
    'ip_address',
])]
class Inquiry extends Model
{
    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'budget_range' => BudgetRange::class,
            'status' => InquiryStatus::class,
            'deadline' => 'date',
        ];
    }

    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }
}
