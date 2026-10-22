<?php

namespace App\Console\Commands;

use App\Models\StudentProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishProfilePictures extends Command
{
    protected $signature = 'profiles:publish-pictures';

    protected $description = 'Publish current profile pictures without exposing verification documents or historical evidence';

    public function handle(): int
    {
        $published = 0;
        $skipped = 0;
        foreach (StudentProfile::whereNotNull('photo_path')->lazyById() as $profile) {
            $path = $profile->photo_path;
            if (! preg_match('#^profile-photos/[0-9]+/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$#', $path)
                || ! Storage::disk('documents')->exists($path)
                || ! in_array(Storage::disk('documents')->mimeType($path), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $skipped++;

                continue;
            }
            Storage::disk('profile_pictures')->put($path, Storage::disk('documents')->get($path));
            $published++;
        }
        $this->info("Published {$published} current profile pictures; skipped {$skipped} unavailable or invalid images.");

        return self::SUCCESS;
    }
}
