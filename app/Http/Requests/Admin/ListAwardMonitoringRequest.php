<?php

namespace App\Http\Requests\Admin;

use App\Enums\AwardStatus;
use App\Enums\BenefitReleaseStatus;
use App\Enums\RenewalStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAwardMonitoringRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'scholarship' => ['nullable', 'integer', 'exists:scholarships,id'],
            'passer_q' => ['nullable', 'string', 'max:120'],
            'listing' => ['nullable', Rule::in(['public', 'private'])],
            'status' => ['nullable', Rule::enum(match ($this->route()->getName()) {
                'admin.renewals.index' => RenewalStatus::class,
                'admin.releases.index' => BenefitReleaseStatus::class,
                default => AwardStatus::class,
            })],
            'provider' => ['nullable', 'integer', 'exists:provider_profiles,id'],
            'term' => ['nullable', 'integer', 'exists:academic_terms,id'],
            'award' => ['nullable', 'integer', 'exists:scholarship_grants,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'activity' => ['nullable', Rule::in(['active', 'inactive'])],
            'consent' => ['nullable', Rule::in(['given', 'missing'])],
        ];
    }
}
