<?php

namespace Database\Seeders;

use App\Http\Requests\Provider\SaveScholarshipRequest;
use App\Http\Requests\Provider\ScholarshipLifecycleRequest;
use App\Models\Course;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\ScholarshipManagement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ProviderScholarshipsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo scholarships may only be seeded locally or in tests.');
        }
        $this->call(CourseSeeder::class);
        $created = 0;
        Cache::lock('iskoreach-provider-scholarships-seeder', 180)->block(10, function () use (&$created): void {
            $providers = ProviderProfile::with('user')->where('verification_status', 'approved')->whereHas('user', fn ($query) => $query->where('role', 'provider'))->orderBy('id')->get();
            foreach ($providers as $provider) {
                DB::transaction(function () use ($provider, &$created): void {
                    foreach ([0, 1] as $variant) {
                        $key = 'provider-scholarships:'.$provider->id.':'.$variant;
                        $marker = $provider->user->activityLogs()->where('action', 'demo.scholarship_seeded')->where('changes->seed_key', $key)->first();
                        if ($marker) {
                            $existing = Scholarship::withTrashed()->find($marker->subject_id);
                            if ($existing && $existing->provider_id !== $provider->id) {
                                throw new RuntimeException('A seeded scholarship has an unexpected owner.');
                            }

                            continue;
                        }
                        $request = SaveScholarshipRequest::create('/provider/scholarships', 'POST', $this->data($provider, $variant));
                        $request->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $provider->user);
                        $request->validateResolved();
                        $management = app(ScholarshipManagement::class);
                        $scholarship = $management->save($provider->user, $request->validated());
                        $publication = ['action' => 'publish', 'version' => $scholarship->management_version, 'confirmed' => true];
                        $lifecycle = new ScholarshipLifecycleRequest;
                        $management->transition($provider->user, $scholarship, Validator::make($publication, $lifecycle->rules())->validate());
                        $provider->user->activityLogs()->create(['action' => 'demo.scholarship_seeded', 'subject_type' => Scholarship::class, 'subject_id' => $scholarship->id,
                            'changes' => ['seed_key' => $key, 'development_only' => true, 'workflow' => 'Validated provider form, draft creation, and publication.'], 'occurred_at' => now()]);
                        $created++;
                    }
                });
            }
        });
        $this->command?->info($created.' demo scholarships created through the provider workflow. Each approved provider has an open and an upcoming sample. Pending/rejected providers remain restricted; rerun after approval. Existing scholarships and edits are preserved.');
    }

    /** @return array<string, mixed> */
    private function data(ProviderProfile $provider, int $variant): array
    {
        $defaults = $provider->scholarship_eligibility ?? [];
        $level = $defaults['priority_education_level'] ?? 'college';
        if ($variant === 1) {
            $level = $level === 'college' ? 'shs_graduate' : 'college';
        }
        $courses = Course::where('education_level', $level === 'shs_graduate' ? 'senior_high' : $level)->orderBy('id')->limit(2)->pluck('name')->all();
        $years = $level === 'college' ? [1, 2, 3, 4] : ($level === 'senior_high' ? [11, 12] : []);
        $education = match ($level) {
            'senior_high' => 'Senior High School', 'shs_graduate' => 'SHS Graduate', default => 'College'
        };
        $year = now('Asia/Manila')->year;
        $required = $level === 'shs_graduate' ? ['Certificate of SHS Graduation', 'Final SHS Report Card'] : ($level === 'senior_high' ? ['School ID', 'Latest Report Card'] : ['Certificate of Registration', 'Certificate of Grades']);
        $requirements = [];
        foreach ([...$required, 'Updated Enrollment and Grades'] as $index => $name) {
            $requirements[] = ['name' => $name, 'description' => 'For this fictional demo, attach a clearly labelled sample document. Do not use real identity or financial documents.',
                'required' => true, 'requirement_stage' => $index === 2 ? 'renewal' : 'application', 'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_size_kb' => 1024];
        }

        return ['title' => '[DEMO] '.$education.' Learning Grant - Provider '.$provider->id.($variant === 0 ? ' Open' : ' Upcoming'),
            'purpose' => 'Practice the complete scholarship application, provider review, renewal, and grant workflow.',
            'description' => 'This is a fictional scholarship for local system testing. It is not an official offer and provides no real funds. Review the academic requirements and coverage, prepare the required sample documents, then submit through your approved IskoReach student account.',
            'benefits_description' => 'Sample tuition, learning materials, and transport support. Renewal requires updated enrollment and grade evidence.', 'benefit_amount' => 10000 + $variant * 5000, 'currency' => 'PHP',
            'academic_year' => $year.'-'.($year + 1), 'slots' => 20 + $variant * 10, 'waitlist_slots' => 5 + $variant * 5,
            'opens_at' => ($variant === 0 ? now('Asia/Manila')->subDay() : now('Asia/Manila')->addDays(14))->format('Y-m-d\TH:i'),
            'deadline_at' => now('Asia/Manila')->addDays($variant === 0 ? 30 : 60)->format('Y-m-d\TH:i'),
            'accepts_existing_scholars' => true, 'existing_scholars_low_priority' => true,
            'priority_education_level' => $level, 'priority_courses' => $courses, 'priority_year_levels' => $years,
            'eligibility_criteria' => "Meet the selected education, course or strand, and year-level requirements.\nProvide the listed sample documents.\nExisting scholars enter the waitlist directly.",
            'locations' => $variant === 0 && filled($provider->coverage_areas) ? array_map(fn (array $area): array => collect($area)->only(['scope', 'region_name', 'province_name', 'city_name'])->all(), $provider->coverage_areas) : [['scope' => 'nationwide']],
            'requirements' => $requirements, 'application_method' => 'internal'];
    }
}
