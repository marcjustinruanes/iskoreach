<?php

namespace App\Http\Requests\Admin;

use App\Services\AdminAuditHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListActivityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function rules(): array
    {
        return ['action' => ['nullable', 'string', Rule::in(array_keys(AdminAuditHistory::ACTIONS))],
            'type' => ['nullable', 'string', Rule::in(array_keys(AdminAuditHistory::TYPES))],
            'actor' => ['nullable', 'integer', 'min:1', 'exists:users,id'], 'record' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])]];
    }
}
