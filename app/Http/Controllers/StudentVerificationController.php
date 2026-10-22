<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\StudentDocument;
use App\Models\StudentVerification;
use App\Models\User;
use App\Services\PrivateDocuments;
use App\Services\StudentAccountSetup;
use App\Services\StudentRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentVerificationController extends Controller
{
    public function photo(Request $request, StudentAccountSetup $setup): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096']]);
        $path = $request->file('photo')->store('profile-photos/'.$request->user()->id, 'documents');
        $previousPath = null;
        try {
            Storage::disk('profile_pictures')->put($path, Storage::disk('documents')->get($path));
            DB::transaction(function () use ($request, $setup, $path, &$previousPath): void {
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                $previousPath = $user->studentProfile->photo_path;
                $setup->invalidate($user);
                $user->studentProfile->forceFill(['photo_path' => $path])->save();
            });
        } catch (\Throwable $error) {
            Storage::disk('documents')->delete($path);
            Storage::disk('profile_pictures')->delete($path);
            throw $error;
        }

        $this->retirePhoto($previousPath);

        return back()->with('status', 'Profile picture saved.');
    }

    public function removePhoto(Request $request, StudentAccountSetup $setup): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $previousPath = null;
        DB::transaction(function () use ($request, $setup, &$previousPath): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $previousPath = $user->studentProfile->photo_path;
            $setup->invalidate($user);
            $user->studentProfile->forceFill(['photo_path' => null])->save();
        });

        $this->retirePhoto($previousPath);

        return back()->with('status', 'Profile picture removed from your profile. Historical verification evidence is retained privately.');
    }

    public function showPhoto(Request $request): StreamedResponse
    {
        return $this->privateFile($request->user()->studentProfile?->photo_path, 'profile-picture', true);
    }

    public function document(Request $request, string $kind, PrivateDocuments $documents, StudentAccountSetup $setup): RedirectResponse
    {
        abort_unless(array_key_exists($kind, config('student-verification.documents')), 404);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:1024']]);
        $storedPath = null;
        try {
            DB::transaction(function () use ($request, $kind, $documents, $setup, &$storedPath): void {
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                $file = $documents->upload($user, $request->file('file'), config('student-verification.documents.'.$kind));
                $storedPath = $file->path;
                $setup->invalidate($user);
                $user->studentProfile->forceFill(['verification_documents' => array_replace($user->studentProfile->verification_documents ?? [], [$kind => $file->id])])->save();
            });
        } catch (\Throwable $error) {
            if ($storedPath) {
                Storage::disk('documents')->delete($storedPath);
            }
            throw $error;
        }

        return back()->with('status', 'Verification document uploaded privately.');
    }

    public function submit(Request $request, StudentAccountSetup $setup): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $setup): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $state = $setup->data($user);
            if (! $state['verification']['eligible'] || in_array($state['verification']['status'], ['pending', 'verified'], true)) {
                throw ValidationException::withMessages(['verification' => 'Complete every eligibility condition. A pending or verified account cannot submit another request.']);
            }
            $profile = $user->studentProfile;
            StudentVerification::create(['user_id' => $user->id, 'snapshot' => ['profile' => $profile->only(StudentRegistration::FIELDS), 'email' => $user->email, 'photo_path' => $profile->photo_path, 'documents' => $profile->verification_documents]]);
        });

        return back()->with('status', 'Verification submitted for administrator review. This does not restrict ordinary scholarship applications.');
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        return Inertia::render('admin/StudentVerifications', ['requests' => StudentVerification::with('user:id,name,email')->latest('id')->paginate(12)->through(fn ($item): array => ['id' => $item->id, 'name' => $item->user->name, 'status' => $item->status, 'url' => route('admin.student-verifications.show', $item)])]);
    }

    public function show(Request $request, StudentVerification $verification): Response
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $verification->snapshot;
        unset($data['photo_path'],$data['documents']);

        return Inertia::render('admin/StudentVerification', ['verification' => ['id' => $verification->id, 'name' => $verification->user->name, 'status' => $verification->status, 'reason' => $verification->reason, 'snapshot' => $data, 'photo_url' => ! empty($verification->snapshot['photo_path']) ? route('admin.student-verifications.file', [$verification, 'photo']) : null, 'documents' => collect($verification->snapshot['documents'])->map(fn ($id, $kind): array => ['kind' => $kind, 'url' => route('admin.student-verifications.file', [$verification, $kind])])->values()]]);
    }

    public function review(Request $request, StudentVerification $verification, StudentAccountSetup $setup): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['status' => ['required', 'in:verified,rejected,revoked'], 'reason' => ['required', 'string', 'min:5', 'max:2000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $verification, $data, $setup): void {
            $user = User::lockForUpdate()->findOrFail($verification->user_id);
            $item = StudentVerification::lockForUpdate()->findOrFail($verification->id);
            abort_unless($user->role === UserRole::Student, 403);
            if (($data['status'] === 'revoked' && $item->status !== 'verified') || ($data['status'] !== 'revoked' && $item->status !== 'pending')) {
                throw ValidationException::withMessages(['status' => 'This request changed or is no longer reviewable.']);
            }
            if ($data['status'] === 'verified' && (! $setup->data($user)['verification']['eligible'] || $item->snapshot['email'] !== $user->email)) {
                throw ValidationException::withMessages(['status' => 'The student no longer meets the verification conditions.']);
            }
            $item->update(['status' => $data['status'], 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $request->user()->activityLogs()->create(['action' => 'student.verification_reviewed', 'subject_type' => StudentVerification::class, 'subject_id' => $item->id, 'changes' => ['status' => $data['status'], 'reason' => $data['reason']], 'occurred_at' => now()]);
            $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'student_verification', 'data' => ['title' => 'Student verification update', 'message' => $data['status'].': '.$data['reason'], 'url' => route('student.profile').'#verification']]);
        });

        return back()->with('status', 'Student verification decision saved.');
    }

    public function file(Request $request, StudentVerification $verification, string $kind): StreamedResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        if ($kind === 'photo') {
            return $this->privateFile($verification->snapshot['photo_path'], 'verification-photo', true);
        }
        $document = StudentDocument::withTrashed()->where('student_id', $verification->user_id)->findOrFail($verification->snapshot['documents'][$kind] ?? 0);
        abort_unless(Storage::disk('documents')->exists($document->path) && hash_equals($document->checksum, hash_file('sha256', Storage::disk('documents')->path($document->path))), 404);

        return $this->privateFile($document->path, $document->original_name);
    }

    private function retirePhoto(?string $path): void
    {
        if ($path) {
            Storage::disk('profile_pictures')->delete($path);
        }
        if ($path && ! StudentVerification::where('snapshot->photo_path', $path)->exists()) {
            Storage::disk('documents')->delete($path);
        }
    }

    private function privateFile(?string $path, string $name, bool $inline = false): StreamedResponse
    {
        abort_unless($path && Storage::disk('documents')->exists($path), 404);
        $mime = Storage::disk('documents')->mimeType($path);
        abort_if($inline && ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return Storage::disk('documents')->response($path, $name, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $inline ? 'inline' : 'attachment');
    }
}
