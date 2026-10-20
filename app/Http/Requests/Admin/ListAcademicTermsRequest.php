<?php

namespace App\Http\Requests\Admin;

use App\Models\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAcademicTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AcademicTerm::class) ?? false;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'usage' => ['nullable', Rule::in(['used', 'unused'])],
            'sort' => ['nullable', Rule::in(['year_desc', 'year_asc', 'latest'])]];
    }
}
