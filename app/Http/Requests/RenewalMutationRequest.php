<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenewalMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['confirmed' => ['required', 'accepted'], 'version' => ['required', 'integer', 'min:0'],
            'action' => ['required', 'in:attach,remove,submit,approved,rejected'],
            'requirement_index' => ['required_if:action,attach,remove', 'integer', 'min:0'],
            'document_id' => ['required_if:action,attach', 'nullable', 'integer'],
            'reason' => ['required_if:action,approved,rejected', 'nullable', 'string', 'max:2000']];
    }
}
