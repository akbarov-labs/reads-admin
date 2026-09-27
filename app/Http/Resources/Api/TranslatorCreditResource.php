<?php

namespace App\Http\Resources\Api;

use App\Models\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A translator as credited on an edition: who, and in what role.
 *
 * @mixin Translator
 */
class TranslatorCreditResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'role' => $this->pivot?->role?->value,
        ];
    }
}
