<?php

namespace App\Http\Requests\Api;

use App\Enums\BudgetRange;
use App\Enums\ProjectType;
use App\Models\Translator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInquiryRequest extends FormRequest
{
    /**
     * Public endpoint — anyone may submit an inquiry.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'from_language' => ['required', 'string', 'max:255'],
            'to_language' => ['required', 'string', 'max:255'],
            'project_type' => ['required', Rule::enum(ProjectType::class)],
            'word_count' => ['nullable', 'integer', 'min:1'],
            'budget_range' => ['nullable', Rule::enum(BudgetRange::class)],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Honeypot: a real visitor never sees or fills this field (hidden
            // off-screen in the form). Always "valid" here — the controller
            // checks it and silently drops anything that filled it in,
            // rather than returning an error a bot script could learn from.
            'website' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Translator $translator */
            $translator = $this->route('translator');

            $from = $this->input('from_language');
            $to = $this->input('to_language');

            if (! $from || ! $to) {
                return;
            }

            $offersPair = $translator->languagePairs
                ->contains(fn ($pair) => $pair->from_language === $from && $pair->to_language === $to);

            if (! $offersPair) {
                $validator->errors()->add(
                    'to_language',
                    'This translator does not list that language pair. Please choose one of their offered pairs.',
                );
            }
        });
    }

    public function isHoneypotFilled(): bool
    {
        return filled($this->input('website'));
    }
}
