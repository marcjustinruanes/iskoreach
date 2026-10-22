<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Services\ProviderRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderVerificationDocumentController extends Controller
{
    public function update(Request $request, ProviderProfile $providerProfile): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Provider && $providerProfile->user_id === $request->user()->id && $providerProfile->registration_version > 0, 403);
        $rules = ['expected_version' => ['required', 'integer', 'min:0']];
        foreach (ProviderRegistration::DOCUMENTS as $kind) {
            $rules[$kind] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:1024'];
        }
        $data = $request->validate($rules);
        $stored = [];
        try {
            DB::transaction(function () use ($request, $providerProfile, $data, &$stored): void {
                $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($providerProfile->id);
                if ($profile->verification_version !== (int) $data['expected_version']) {
                    throw ValidationException::withMessages(['expected_version' => 'This account changed. Reload before uploading.']);
                }
                $documents = $profile->verification_documents ?? [];
                $before = $documents;
                foreach (ProviderRegistration::DOCUMENTS as $kind) {
                    if (isset($data[$kind])) {
                        $file = $data[$kind];
                        $path = $file->store('provider-verification/'.$profile->id, 'documents');
                        $stored[] = $path;
                        $documents[$kind] = ['path' => $path, 'name' => $kind.'.'.$file->extension(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
                    }
                }
                if (! $stored) {
                    throw ValidationException::withMessages(['representative_id' => 'Select at least one replacement document.']);
                }
                $previousStatus = $profile->verification_status->value;
                $profile->forceFill(['verification_documents' => $documents, 'verification_status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null, 'verification_version' => $profile->verification_version + 1])->save();
                $request->user()->activityLogs()->create(['action' => 'provider.details_changed', 'subject_type' => ProviderProfile::class, 'subject_id' => $profile->id, 'changes' => ['from' => $previousStatus, 'to' => 'pending', 'previous_document_hashes' => collect($before)->map(fn (array $document): string => $document['sha256'])->all()], 'occurred_at' => now()]);
            });
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                Storage::disk('documents')->delete($path);
            }
            throw $error;
        }

        return to_route('provider.status')->with('status', 'Verification documents submitted. Your account is pending administrator review.');
    }

    public function show(Request $request, ProviderProfile $providerProfile, string $kind): StreamedResponse
    {
        abort_unless($request->user()->role === UserRole::Admin || ($request->user()->role === UserRole::Provider && $providerProfile->user_id === $request->user()->id), 403);
        $document = $providerProfile->verification_documents[$kind] ?? null;
        abort_unless($document && Storage::disk('documents')->exists($document['path']), 404);
        abort_unless(hash_equals($document['sha256'], hash('sha256', Storage::disk('documents')->get($document['path']))), 409, 'This document failed its integrity check.');

        return Storage::disk('documents')->download($document['path'], $document['name'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
