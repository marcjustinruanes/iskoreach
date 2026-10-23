<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentPhotoController extends Controller
{
    public function photo(Request $request): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096']]);
        $path = $request->file('photo')->store('profile-photos/'.$request->user()->id, 'documents');
        $previousPath = null;
        try {
            Storage::disk('profile_pictures')->put($path, Storage::disk('documents')->get($path));
            DB::transaction(function () use ($request, $path, &$previousPath): void {
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                $previousPath = $user->studentProfile->photo_path;
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

    public function removePhoto(Request $request): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $previousPath = null;
        DB::transaction(function () use ($request, &$previousPath): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $previousPath = $user->studentProfile->photo_path;
            $user->studentProfile->forceFill(['photo_path' => null])->save();
        });

        $this->retirePhoto($previousPath);

        return back()->with('status', 'Profile picture removed from your profile.');
    }

    public function showPhoto(Request $request): StreamedResponse
    {
        return $this->privateFile($request->user()->studentProfile?->photo_path, 'profile-picture', true);
    }

    private function retirePhoto(?string $path): void
    {
        if ($path) {
            Storage::disk('profile_pictures')->delete($path);
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
