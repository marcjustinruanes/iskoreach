<?php

namespace App\Services;

use App\Models\PolicyDocument;
use Illuminate\Validation\ValidationException;

class RegistrationPolicies
{
    public function published(string $kind): PolicyDocument
    {
        return PolicyDocument::query()->where('kind', $kind)->whereNotNull('published_at')->latest('id')->firstOrFail();
    }

    /** @return array{terms_version: string, privacy_version: string} */
    public function versions(): array
    {
        return ['terms_version' => $this->published('terms')->version(), 'privacy_version' => $this->published('privacy')->version()];
    }

    /** @return array{policy: string, version: string, title: string, content: string, publishedAt: string} */
    public function page(string $kind): array
    {
        $document = $this->published($kind);

        return ['policy' => $kind, 'version' => $document->version(), 'title' => $document->title,
            'content' => $document->content, 'publishedAt' => $document->published_at->toIso8601String()];
    }

    /** @param array<string, mixed> $input @return array{terms: PolicyDocument, privacy: PolicyDocument} */
    public function accepted(array $input): array
    {
        foreach (['terms', 'privacy'] as $kind) {
            PolicyDocument::query()->where('kind', $kind)->orderBy('id')->lockForUpdate()->firstOrFail();
        }
        $policies = ['terms' => $this->published('terms'), 'privacy' => $this->published('privacy')];
        foreach ($policies as $kind => $policy) {
            if (($input[$kind.'_version'] ?? null) !== $policy->version()) {
                throw ValidationException::withMessages([$kind.'_version' => 'This policy has been updated. Review the latest version and confirm your agreement again.']);
            }
        }

        return $policies;
    }
}
