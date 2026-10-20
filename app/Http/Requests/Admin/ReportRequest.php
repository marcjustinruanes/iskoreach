<?php

namespace App\Http\Requests\Admin;

use App\Services\AdminReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['type' => $this->input('type') ?: 'registrations', 'from' => $this->input('from') ?: now('Asia/Manila')->subDays(29)->toDateString(), 'to' => $this->input('to') ?: now('Asia/Manila')->toDateString()]);
    }

    public function rules(): array
    {
        return ['type' => ['required', 'string', Rule::in(array_keys(AdminReports::DEFINITIONS))],
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->any() && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 365) {
                $validator->errors()->add('to', 'Choose a reporting period of at most 366 days.');
            }
        }];
    }
}
