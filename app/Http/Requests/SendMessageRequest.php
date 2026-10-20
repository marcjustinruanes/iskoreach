<?php

namespace App\Http\Requests;

use App\Services\PrivateDocuments;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('conversation')) ?? false;
    }

    public function rules(): array
    {
        return ['submission_token' => ['required', 'uuid'], 'body' => ['nullable', 'string', 'max:5000', 'required_without:file'],
            'file' => ['nullable', 'required_without:body', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.app(PrivateDocuments::class)->uploadLimitKilobytes()]];
    }
}
