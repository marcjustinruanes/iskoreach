<?php

namespace App\Http\Requests\Provider;

use App\Models\Scholarship;
use Illuminate\Foundation\Http\FormRequest;

class ReportApplicantCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scholarship = $this->route('scholarship');

        return $scholarship instanceof Scholarship && ($this->user()?->can('reportExternalCount', $scholarship) ?? false);
    }

    public function rules(): array
    {
        return [
            'reported_count' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'source_label' => ['required', 'string', 'max:200'],
            'source_as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'duplicates_excluded' => ['accepted'], 'submission_token' => ['required', 'uuid'],
        ];
    }
}
