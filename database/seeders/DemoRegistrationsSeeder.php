<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use App\Services\PhilippineLocations;
use App\Services\ProviderRegistration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoRegistrationsSeeder extends Seeder
{
    public const CREDENTIALS_FILE = 'demo-registration-credentials.json';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo registrations may only be seeded locally or in tests.');
        }

        $this->call(CourseSeeder::class);
        Cache::lock('iskoreach-demo-registrations-seeder', 120)->block(10, function (): void {
            $disk = Storage::disk('local');
            $credentials = $disk->exists(self::CREDENTIALS_FILE)
                ? json_decode($disk->get(self::CREDENTIALS_FILE), true, 512, JSON_THROW_ON_ERROR) : [];
            foreach (['student', 'provider'] as $role) {
                foreach (['approved', 'rejected', 'pending'] as $status) {
                    foreach (range(1, $status === 'approved' ? 4 : 3) as $variant) {
                        $email = 'iskoreach.demo.'.$role.'.'.$status.'.'.$variant.'@gmail.com';
                        $user = User::where('email', $email)->first();
                        if ($user && ($user->role->value !== $role || ! isset($credentials[$email]) || ! Hash::check($credentials[$email]['password'], $user->password))) {
                            throw new RuntimeException('An existing account conflicts with the demo email: '.$email.'. No account was overwritten.');
                        }
                        $credentials[$email] ??= ['role' => $role, 'initial_status' => $status, 'password' => 'Ir!'.bin2hex(random_bytes(12))];
                    }
                }
            }
            if (! $disk->put(self::CREDENTIALS_FILE, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not save private demo registration credentials.');
            }
            $addresses = collect($credentials)->contains(fn (array $credential): bool => ($credential['location_version'] ?? 0) < 2) ? $this->addresses() : [];
            DB::transaction(function () use ($credentials, $addresses): void {
                $index = 0;
                foreach ($credentials as $email => $credential) {
                    $index++;
                    $existing = User::where('email', $email)->first();
                    if ($existing) {
                        if (($credential['location_version'] ?? 0) < 2) {
                            $address = $addresses[$index - 1];
                            if ($credential['role'] === 'student') {
                                $profile = $existing->studentProfile;
                                $profile->forceFill([...$address, ...$this->school($address, $profile->education_level)])->save();
                            } else {
                                $existing->providerProfile->forceFill([...$address, 'coverage_areas' => $this->coverage($address, $index)])->save();
                            }
                        }

                        continue;
                    }
                    $student = $credential['role'] === 'student';
                    $status = $credential['initial_status'];
                    $names = [['Ana', 'Santos'], ['Luis', 'Cruz'], ['Mia', 'Reyes'], ['Paolo', 'Garcia'], ['Ella', 'Torres'], ['Marco', 'Ramos'], ['Sofia', 'Lopez'], ['Carlo', 'Mendoza'], ['Nina', 'Castillo'], ['Miguel', 'Flores'], ['Lara', 'Navarro'], ['Jose', 'Rivera'], ['Isabel', 'Aquino'], ['Daniel', 'Bautista'], ['Camille', 'Fernandez'], ['Rafael', 'Villanueva'], ['Andrea', 'Gonzales'], ['Gabriel', 'Soriano'], ['Bianca', 'Mercado'], ['Nathan', 'Salazar']];
                    $person = ['first_name' => $names[$index - 1][0], 'middle_name' => $index % 2 === 0 ? null : 'Dela Cruz', 'surname' => $names[$index - 1][1], 'suffix' => null,
                        'contact_number' => '+63991'.str_pad((string) $index, 7, '0', STR_PAD_LEFT)];
                    $user = new User;
                    $user->forceFill(['name' => implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['surname']])),
                        'email' => $email, 'password' => Hash::make($credential['password']), 'role' => $student ? UserRole::Student : UserRole::Provider])->save();
                    $address = $addresses[$index - 1];
                    if ($student) {
                        $level = $index % 3 === 1 ? 'college' : 'senior_high';
                        $graduate = $index % 3 === 0;
                        $course = Course::where('education_level', $level)->orderBy('id')->firstOrFail();
                        $profile = $user->studentProfile()->make();
                        $profile->forceFill([...$person, ...$address, 'date_of_birth' => now()->subYears($level === 'college' ? 20 : 18)->subDays($index)->toDateString(), 'sex' => $index % 2 === 0 ? 'male' : 'female',
                            'student_number' => $graduate ? null : 'DEMO-REG-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                            ...$this->school($address, $level),
                            'education_level' => $level, 'course' => $course->name, 'year_level' => $level === 'college' ? 2 : ($graduate ? 12 : 11),
                            'graduation_year' => $graduate ? now()->year : null, 'street_address' => 'Demo registration address '.$index,
                            'registration_status' => $status, 'registration_review_reason' => $status === 'rejected' ? 'DEMO: School information needs correction. Update your registration and submit it again.' : null])->save();
                    } else {
                        $type = $index % 2 === 0 ? 'individual' : 'foundation';
                        $profile = $user->providerProfile()->make();
                        $profile->forceFill([...$person, ...$address, 'organization_name' => '[DEMO] '.ucfirst($status).' Scholarship Provider '.$index,
                            'organization_description' => 'Fictional organization for testing registration, administrator review, and scholarship management.',
                            'country' => 'PH', 'organization_type' => $type, 'position' => 'Scholarship Coordinator', 'contact_email' => $email, 'work_email' => $email,
                            'organization_phone' => '+63992'.str_pad((string) $index, 7, '0', STR_PAD_LEFT), 'authorization_required' => $index % 2 !== 0,
                            'verification_status' => $status, 'registration_version' => 1, 'verification_version' => $status === 'pending' ? 0 : 1,
                            'reviewed_at' => $status === 'pending' ? null : now(), 'reviewed_by' => $status === 'pending' ? null : User::where('role', UserRole::Admin)->value('id'),
                            'rejection_reason' => $status === 'rejected' ? 'DEMO: Organization evidence is incomplete. Replace the document and submit again.' : null,
                            'coverage_areas' => $this->coverage($address, $index),
                            'scholarship_eligibility' => ['priority_education_level' => $type === 'foundation' ? 'college' : 'shs_graduate',
                                'priority_courses' => [], 'priority_year_levels' => $type === 'foundation' ? [1, 2, 3, 4] : []]])->save();
                        $documents = [];
                        foreach (app(ProviderRegistration::class)->requiredDocuments($profile->only('organization_type', 'authorization_required')) as $kind) {
                            $path = 'provider-verification/'.$profile->id.'/demo-'.$kind.'.pdf';
                            $contents = $this->document($kind);
                            Storage::disk('documents')->put($path, $contents);
                            $documents[$kind] = ['path' => $path, 'name' => 'DEMO-'.$kind.'.pdf', 'mime' => 'application/pdf', 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
                        }
                        $profile->forceFill(['verification_documents' => $documents])->save();
                    }
                    $user->activityLogs()->create(['action' => 'demo.registration_seeded', 'subject_type' => $profile::class, 'subject_id' => $profile->id,
                        'changes' => ['development_only' => true, 'initial_status' => $status, 'reason' => 'Fictional demo registration; no email was sent and no real identity was verified.'], 'occurred_at' => now()]);
                }
            });
            foreach ($credentials as &$credential) {
                $credential['location_version'] = 2;
            }
            unset($credential);
            if (! $disk->put(self::CREDENTIALS_FILE, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not save the demo location version.');
            }
        });
        $this->command?->info('Demo registrations ready: 10 students and 10 providers (4 approved, 3 rejected, and 3 pending per role). Existing accounts are preserved.');
        $this->command?->info('Private login credentials: '.Storage::disk('local')->path(self::CREDENTIALS_FILE));
    }

    /** @return list<array<string, string>> */
    private function addresses(): array
    {
        $locations = app(PhilippineLocations::class);
        $available = collect($locations->regions()['data']);
        $regions = $available->whereIn('code', ['030000000', '040000000', '070000000', '110000000'])->values();
        if ($regions->isEmpty()) {
            $regions = $available->take(4)->values();
        }
        $addresses = [];
        for ($index = 0; $index < 20; $index++) {
            $region = $regions[$index % $regions->count()];
            $cities = $locations->cities($region['code'])['data'];
            $city = $cities[intdiv($index, $regions->count()) % count($cities)];
            $province = isset($city['province_code']) ? $locations->choice($locations->provinces($region['code'])['data'], $city['province_code']) : null;
            $barangays = $locations->barangays($city['code'])['data'];
            if ($barangays === []) {
                throw new RuntimeException('The demo location has no available barangays.');
            }
            $addresses[] = ['region_name' => $region['name'], 'province_name' => $province['name'] ?? PhilippineLocations::NO_PROVINCE,
                'city_name' => $city['name'], 'barangay_name' => $barangays[0]['name']];
        }

        return $addresses;
    }

    /** @param array<string, string> $address @return array<string, string> */
    private function school(array $address, string $level): array
    {
        return ['school' => '[DEMO] '.$address['city_name'].($level === 'college' ? ' College' : ' Senior High School'),
            'campus_region_name' => $address['region_name'], 'campus_province_name' => $address['province_name'], 'campus_city_name' => $address['city_name']];
    }

    /** @param array<string, string> $address @return list<array<string, string>> */
    private function coverage(array $address, int $index): array
    {
        $scope = ['nationwide', 'region', 'province', 'city'][$index % 4];
        if ($scope === 'province' && $address['province_name'] === PhilippineLocations::NO_PROVINCE) {
            $scope = 'city';
        }

        return [['scope' => $scope, 'region_name' => $scope === 'nationwide' ? '' : $address['region_name'],
            'province_name' => in_array($scope, ['province', 'city'], true) ? $address['province_name'] : '', 'city_name' => $scope === 'city' ? $address['city_name'] : '']];
    }

    private function document(string $kind): string
    {
        $stream = 'BT /F1 16 Tf 40 740 Td (DEMO DOCUMENT - NOT VALID FOR OFFICIAL USE) Tj 0 -30 Td ('.str_replace('_', ' ', $kind).') Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
