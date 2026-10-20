<?php

namespace App\Http\Requests\Student;

use App\Services\PrivateDocuments;
use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->value === 'student';
    }

    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:120'], 'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'extensions:pdf,jpg,jpeg,png', 'max:'.app(PrivateDocuments::class)->uploadLimitKilobytes()]];
    }
}
