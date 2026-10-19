<?php

namespace App\Http\Requests;

use App\Enums\BronzeRule;
use App\Enums\DisqualifiedPodiumRule;
use App\Enums\DrawCriteria;
use App\Enums\EventStatus;
use App\Enums\PreResultsWithdrawalRule;
use App\Enums\UnfoughtWithdrawalRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La propiedad se comprueba en el middleware can:manage,event; crear solo exige sesión y no ser una cuenta de controlador de tatami.
        return ! $this->user()?->isTatamiAccount();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'discipline' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:200'],
            'status' => ['required', Rule::enum(EventStatus::class)],
            'is_public' => ['boolean'],
            'registration_form_enabled' => ['boolean'],
            'is_test' => ['boolean'],
            'show_photos_publicly' => ['boolean'],
            'dq_podium_rule' => ['sometimes', 'required', Rule::enum(DisqualifiedPodiumRule::class)],
            'unfought_withdrawal_rule' => ['sometimes', 'required', Rule::enum(UnfoughtWithdrawalRule::class)],
            'default_bronze_rule' => ['sometimes', 'required', Rule::enum(BronzeRule::class)],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', Rule::when($this->filled('registration_opens_at'), ['after:registration_opens_at'])],
            'tatami_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                $event = $this->route('event');
                $max = $event?->categories()->max('tatami');
                if ($max !== null && (int) $value < (int) $max) {
                    $fail("Hay categorías asignadas al tatami {$max}: reasígnalas antes de reducir el número de tatamis.");
                }
            }],
            'rules_text' => ['nullable', 'string', 'max:20000'],
            'rules_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'remove_rules_pdf' => ['boolean'],
            'poster' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_poster' => ['boolean'],
            'draw_criteria' => ['sometimes', 'required', Rule::enum(DrawCriteria::class)],
            'withdrawal_before_results_rule' => ['sometimes', 'required', Rule::enum(PreResultsWithdrawalRule::class)],
        ];
    }
}
