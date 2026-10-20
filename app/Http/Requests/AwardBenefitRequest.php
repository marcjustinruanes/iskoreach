<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->value === 'provider';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:award_create,award_complete,award_revoke,release_create,release_process,release_release'],
            'version' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'academic_term_id' => ['required_if:action,award_create,release_create', 'nullable', 'integer', 'exists:academic_terms,id'],
            'starts_on' => ['required_if:action,award_create', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'conflict_confirmed' => ['sometimes', 'boolean'],
            'context_token' => ['required_if:action,award_create', 'nullable', 'string', 'size:64'],
            'amount' => ['required_if:action,release_create', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'currency' => ['required_if:action,release_create', 'nullable', 'in:PHP,USD,EUR'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'released_at' => ['required_if:action,release_release', 'nullable', 'date_format:Y-m-d\\TH:i'],
            'submission_token' => ['required_if:action,release_create', 'nullable', 'uuid'],
        ];
    }
}
