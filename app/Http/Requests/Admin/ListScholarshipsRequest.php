<?php

namespace App\Http\Requests\Admin;

use App\Enums\ScholarshipStatus;
use App\Models\Scholarship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListScholarshipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Scholarship::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'records' => ['nullable', Rule::in(['all', 'current', 'retained'])],
            'q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::enum(ScholarshipStatus::class)],
            'provider' => ['nullable', 'integer', 'exists:provider_profiles,id'], 'category' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'opens_from' => ['nullable', 'date_format:Y-m-d'], 'opens_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('opens_from') ? ['after_or_equal:opens_from'] : [])],
            'deadline_from' => ['nullable', 'date_format:Y-m-d'], 'deadline_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('deadline_from') ? ['after_or_equal:deadline_from'] : [])],
        ];
    }
}
