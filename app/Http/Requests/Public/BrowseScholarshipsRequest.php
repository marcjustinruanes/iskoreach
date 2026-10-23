<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrowseScholarshipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:200'], 'location' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'string', 'max:100'],
            'availability' => ['nullable', Rule::in(['open', 'upcoming', 'closed'])], 'existing' => ['nullable', Rule::in(['yes', 'no'])],
            'sort' => ['nullable', Rule::in(['deadline', 'published', 'amount'])],
            'term' => ['nullable', 'integer', 'exists:academic_terms,id'], 'currency' => ['nullable', Rule::in(['PHP', 'USD'])],
            'amount_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'], 'amount_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', ...($this->filled('amount_min') ? ['gte:amount_min'] : [])],
            'opens_from' => ['nullable', 'date_format:Y-m-d'], 'opens_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('opens_from') ? ['after_or_equal:opens_from'] : [])],
            'deadline_from' => ['nullable', 'date_format:Y-m-d'], 'deadline_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('deadline_from') ? ['after_or_equal:deadline_from'] : [])]];
    }
}
