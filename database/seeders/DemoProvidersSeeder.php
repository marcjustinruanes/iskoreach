<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoProvidersSeeder extends Seeder
{
    public const CREDENTIALS_FILE = 'demo-provider-credentials.json';

    public const PROVIDERS = [
        ['key' => 'test-foundation', 'name' => 'IskoReach Test Foundation', 'first_name' => 'Ana', 'surname' => 'Demo', 'type' => 'foundation'],
        ['key' => 'future-scholars', 'name' => 'Future Scholars Organization [TEST]', 'first_name' => 'Luis', 'surname' => 'Demo', 'type' => 'organization'],
        ['key' => 'student-opportunity', 'name' => 'Student Opportunity Foundation [TEST]', 'first_name' => 'Mia', 'surname' => 'Demo', 'type' => 'foundation'],
        ['key' => 'academic-excellence', 'name' => 'Academic Excellence Foundation [TEST]', 'first_name' => 'Paolo', 'surname' => 'Demo', 'type' => 'foundation'],
        ['key' => 'community-education', 'name' => 'Community Education Support Center [TEST]', 'first_name' => 'Ella', 'surname' => 'Demo', 'type' => 'organization'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo providers may only be seeded in local or testing environments.');
        }

        Cache::lock('iskoreach-demo-providers-seeder', 120)->block(10, function (): void {
            $disk = Storage::disk('local');
            $credentials = $disk->exists(self::CREDENTIALS_FILE)
                ? json_decode($disk->get(self::CREDENTIALS_FILE), true, 512, JSON_THROW_ON_ERROR) : [];

            foreach (self::PROVIDERS as $provider) {
                $email = 'demo.'.$provider['key'].'@example.com';
                $user = User::query()->where('email', $email)->first();
                if ($user && ($user->role !== UserRole::Provider || ! isset($credentials[$email]) || ! Hash::check($credentials[$email]['password'], $user->password) || $user->providerProfile?->organization_name !== $provider['name'])) {
                    throw new RuntimeException('Existing account conflicts with demo setup: '.$email.'. No passwords or accounts were overwritten.');
                }
                $credentials[$email] ??= ['provider' => $provider['name'], 'password' => 'Ir!'.bin2hex(random_bytes(18))];
            }

            if (! $disk->put(self::CREDENTIALS_FILE, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not save private local demo credentials.');
            }

            DB::transaction(function () use ($credentials): void {
                foreach (self::PROVIDERS as $index => $provider) {
                    $email = 'demo.'.$provider['key'].'@example.com';
                    $user = User::query()->where('email', $email)->first();
                    if (! $user) {
                        $user = new User;
                        $user->forceFill(['name' => $provider['first_name'].' '.$provider['surname'], 'email' => $email,
                            'password' => Hash::make($credentials[$email]['password']), 'role' => UserRole::Provider,
                            'email_verified_at' => now(), 'requires_email_verification' => true])->save();
                        $profile = new ProviderProfile;
                        $profile->forceFill(['user_id' => $user->id, 'organization_name' => $provider['name'],
                            'contact_email' => $email, 'work_email' => $email, 'first_name' => $provider['first_name'],
                            'surname' => $provider['surname'], 'position' => 'Demo Scholarship Administrator',
                            'contact_number' => '0900000000'.($index + 1), 'organization_phone' => '0900000000'.($index + 1),
                            'organization_type' => $provider['type'], 'office_address' => 'Demo office '.($index + 1).' (fictional address for local testing only)',
                            'region_code' => '130000000', 'city_code' => '137404000', 'region_name' => 'National Capital Region',
                            'city_name' => 'Quezon City', 'verification_status' => 'approved', 'verification_version' => 1,
                            'reviewed_at' => now(), 'registration_version' => 0, 'authorization_required' => false])->save();
                        $user->activityLogs()->create(['action' => 'demo.provider_seeded', 'subject_type' => ProviderProfile::class,
                            'subject_id' => $profile->id, 'changes' => ['development_only' => true,
                                'reason' => 'Local test provider approval, not administrator identity/document verification.'], 'occurred_at' => now()]);
                    }
                    $this->scholarships($user->providerProfile, $provider['key'], $index);
                }
            });
        });

        $this->command?->info('Demo setup complete: 5 dedicated providers and 10 demo scholarships. Existing records/passwords are preserved.');
        $this->command?->info('Private credentials: '.Storage::disk('local')->path(self::CREDENTIALS_FILE));
    }

    private function scholarships(ProviderProfile $profile, string $key, int $index): void
    {
        $titles = ['Learning Access Grant', 'Future STEM Grant', 'Student Essentials Grant', 'Academic Merit Grant', 'Community Learning Grant'];
        foreach ([0, 1] as $variant) {
            $slug = 'iskoreach-demo-'.$key.'-'.($variant + 1);
            $existing = Scholarship::withTrashed()->where('slug', $slug)->first();
            if ($existing) {
                if ($existing->provider_id !== $profile->id) {
                    throw new RuntimeException('Demo scholarship ownership conflict: '.$slug);
                }

                continue;
            }
            $upcoming = $variant === 1 && in_array($index, [1, 2], true);
            $closed = $variant === 1 && in_array($index, [3, 4], true);
            $item = Scholarship::query()->create(['provider_id' => $profile->id,
                'title' => '[DEMO] '.$titles[$index].($variant === 0 ? ' - Nationwide' : ' - NCR'), 'slug' => $slug,
                'description' => 'TEST / DEMO SCHOLARSHIP ONLY. This fictional opportunity is for local IskoReach testing; it is not an official offer and no funds are available. Practice preparing an application, uploading requirements, provider review, renewals, and benefit tracking. The sample grant covers tuition, learning materials, and transport for one semester.',
                'scholarship_type' => $index === 3 ? 'Merit' : 'Financial assistance',
                'benefit_amount' => 10000 + $index * 5000 + $variant * 2500, 'currency' => 'PHP',
                'opens_at' => $upcoming ? now()->addDays(14) : now()->subDays($closed ? 60 : 7),
                'deadline_at' => $closed ? now()->subDays(7) : now()->addDays($upcoming ? 45 : 30 + $index),
                'accepts_existing_scholars' => $index % 2 === 0, 'existing_scholars_low_priority' => $index === 2,
                'require_verified_declarations' => false,
                'eligibility_rules' => ['criteria' => ['Currently enrolled senior high school or college student at a Philippine school or university.',
                    'Provide current enrollment evidence and grades for provider review.',
                    $variant === 0 ? 'Nationwide coverage; applicants from any Philippine region may apply.' : 'For students residing or studying in the National Capital Region.',
                    'Demo use only; do not submit genuine sensitive documents to test fictional eligibility.']],
                'status' => $closed ? 'closed' : 'published', 'published_at' => now()]);
            $item->locations()->create($variant === 0 ? ['scope' => 'nationwide'] : ['scope' => 'region', 'region_code' => '130000000', 'region_name' => 'National Capital Region']);
            foreach (['Certificate of Registration (COR)', 'Certificate of Grades', 'Valid Student ID', ...($index % 2 === 0 ? ['Certificate of Indigency'] : [])] as $order => $name) {
                $item->requirements()->create(['name' => $name, 'description' => 'Upload a clearly labeled dummy '.$name.' for this demo application. Never upload real identity or financial documents for a demo.',
                    'required' => true, 'requirement_stage' => 'application', 'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_size_kb' => 1024, 'sort_order' => $order]);
            }
            $item->requirements()->create(['name' => 'Updated COR and Grades', 'description' => 'Dummy enrollment and grade evidence for semester renewal testing.',
                'required' => true, 'requirement_stage' => 'renewal', 'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_size_kb' => 1024, 'sort_order' => 0]);
        }
    }
}
