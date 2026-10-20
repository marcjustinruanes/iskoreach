<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'role' => ['nullable', Rule::in(['student', 'provider'])],
            'status' => ['nullable', Rule::in(['active', 'pending', 'rejected', 'profile_missing'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name_asc', 'name_desc'])],
        ];
    }
}
