<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReadMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('conversation')) ?? false;
    }

    public function rules(): array
    {
        return ['through_id' => ['required', 'integer', 'min:1']];
    }
}
