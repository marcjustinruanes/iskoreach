<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PublicPassersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Fictional public passers may only be seeded locally or in tests.');
        }

        Cache::lock('iskoreach-public-passers-seeder', 120)->block(10, function (): void {
            DB::transaction(function (): void {
                $provider = $this->account('organization', 'Marisol Rivera', UserRole::Provider);
                $profile = $provider->providerProfile;
                if (! $profile) {
                    $profile = new ProviderProfile;
                    $profile->forceFill(['user_id' => $provider->id, 'organization_name' => 'Hiraya Learning Foundation',
                        'contact_email' => $provider->email, 'work_email' => $provider->email,
                        'first_name' => 'Marisol', 'surname' => 'Rivera', 'position' => 'Program Coordinator',
                        'organization_type' => 'foundation',
                        'verification_status' => 'approved', 'reviewed_at' => now(), 'registration_version' => 0,
                        'authorization_required' => false])->save();
                }
                $today = now('Asia/Manila')->startOfDay();
                $term = AcademicTerm::firstOrCreate(['academic_year' => $today->year.'-'.($today->year + 1), 'semester' => 'Annual scholarship period'],
                    ['starts_on' => $today->copy()->startOfYear()->toDateString(), 'ends_on' => $today->copy()->addYear()->endOfYear()->toDateString()]);
                $programs = [
                    ['Hiraya Academic Excellence Scholarship', 'Merit'],
                    ['Sibol Science and Technology Grant', 'STEM'],
                    ['Bayanihan Learning Assistance', 'Financial assistance'],
                    ['Likha Creative Arts Scholarship', 'Arts'],
                    ['Lakas Student Athlete Scholarship', 'Sports'],
                    ['Paglingap Community Leadership Grant', 'Leadership'],
                    ['Buklod Future Educators Scholarship', 'Education'],
                    ['Alaga Health Sciences Scholarship', 'Health sciences'],
                    ['Ani Agricultural Studies Grant', 'Agriculture'],
                    ['Gabay Technical Skills Scholarship', 'Technical vocational'],
                ];
                $firstNames = ['Amelia', 'Gabriel', 'Sofia', 'Miguel', 'Isabella', 'Rafael', 'Lucia', 'Daniel', 'Camila', 'Adrian',
                    'Elena', 'Joaquin', 'Beatriz', 'Nicolas', 'Clara', 'Andres', 'Natalia', 'Lorenzo', 'Gabriela', 'Mateo',
                    'Andrea', 'Sebastian', 'Bianca', 'Marco', 'Julia', 'Paolo', 'Ariana', 'Luis', 'Celine', 'Enzo',
                    'Diana', 'Carlo', 'Erika', 'Francis', 'Hannah', 'Ivan', 'Jasmine', 'Joshua', 'Katrina', 'Kevin',
                    'Lara', 'Manuel', 'Nina', 'Oliver', 'Patricia', 'Ramon', 'Sabrina', 'Tristan', 'Valerie', 'Xavier'];
                $surnames = ['Santos', 'Reyes', 'Cruz', 'Garcia', 'Mendoza', 'Ramos', 'Torres', 'Flores', 'Rivera', 'Gonzales',
                    'Bautista', 'Villanueva', 'Aquino', 'Castillo', 'Navarro', 'Mercado', 'Salazar', 'Del Rosario', 'Soriano', 'Aguilar',
                    'Valdez', 'Fernandez', 'Perez', 'Dela Pena', 'Domingo', 'Castro', 'Santiago', 'Lim', 'Tolentino', 'Manalo',
                    'Velasco', 'Pascual', 'Gutierrez', 'Espinosa', 'Miranda', 'Rosales', 'Fajardo', 'Abad', 'Ocampo', 'David',
                    'Alvarez', 'Dizon', 'Enriquez', 'Marquez', 'Lazaro', 'Pineda', 'Ignacio', 'Vergara', 'Soliman', 'Buenaventura'];
                foreach ($programs as $index => [$title, $type]) {
                    $slug = 'hiraya-local-passers-'.($index + 1);
                    $scholarship = Scholarship::withTrashed()->where('slug', $slug)->first();
                    if ($scholarship && $scholarship->provider_id !== $profile->id) {
                        throw new RuntimeException('Reserved scholarship slug belongs to another provider.');
                    }
                    if (! $scholarship) {
                        $scholarship = Scholarship::create(['provider_id' => $profile->id, 'academic_term_id' => $term->id,
                            'title' => $title, 'slug' => $slug, 'scholarship_type' => $type,
                            'description' => 'Fictional scholarship for local development previews. No official offer or funds are represented.',
                            'benefit_amount' => 10000 + $index * 2500, 'currency' => 'PHP',
                            'opens_at' => $today->copy()->subMonths(12)->utc(), 'deadline_at' => $today->copy()->subMonths(11)->utc(),
                            'status' => 'published', 'published_at' => $today->copy()->subMonths(12)->utc(),
                            'accepts_existing_scholars' => true, 'eligibility_rules' => ['criteria' => ['Fictional local preview records only.']]]);
                        $scholarship->locations()->create(['scope' => 'nationwide']);
                    }
                    for ($offset = 0; $offset < 5; $offset++) {
                        $number = $index * 5 + $offset;
                        $name = $firstNames[$number].' '.$surnames[$number];
                        $student = $this->account('student-'.($number + 1), $name, UserRole::Student);
                        $student->studentProfile()->firstOrCreate([], ['first_name' => $firstNames[$number], 'surname' => $surnames[$number],
                            'school' => 'Hiraya Learning College', 'course' => $type.' Studies', 'year_level' => ($number % 4) + 1]);
                        $published = $today->copy()->subDays(10 + $number * 6)->setTime(9 + ($number % 7), 0)->utc();
                        $application = Application::withTrashed()->firstOrCreate(['student_id' => $student->id, 'scholarship_id' => $scholarship->id],
                            ['academic_term_id' => $term->id, 'status' => 'accepted', 'submitted_at' => $published->copy()->subDays(14),
                                'reviewed_by' => $provider->id, 'reviewed_at' => $published->copy()->subDay(),
                                'decision_reason' => 'Synthetic accepted application for local directory previews.']);
                        if (! ScholarshipAward::where('application_id', $application->id)->exists()) {
                            $award = new ScholarshipAward;
                            $award->forceFill(['application_id' => $application->id, 'academic_term_id' => $term->id,
                                'status' => 'active', 'starts_on' => $published->toDateString(), 'ends_on' => $today->copy()->addYear()->toDateString(),
                                'public_listing_consented_at' => $published->copy()->subHour(), 'published_at' => $published,
                                'public_name' => $name, 'publication_version' => 1])->save();
                            $student->activityLogs()->create(['action' => 'fixture.public_passer_created', 'subject_type' => ScholarshipAward::class,
                                'subject_id' => $award->id, 'changes' => ['development_only' => true,
                                    'reason' => 'Synthetic consent and publication fixture for a fictional person; not real student consent or administrator review.'], 'occurred_at' => now()]);
                        }
                    }
                }
                $reviewNames = [
                    ['Alessandra', 'Cabrera'], ['Benjamin', 'Hernandez'], ['Celestine', 'Robles'], ['Diego', 'Francisco'],
                    ['Estelle', 'Padilla'], ['Felipe', 'Natividad'], ['Georgina', 'Arce'], ['Hector', 'Bernardo'],
                    ['Irene', 'Montes'], ['Jerome', 'Evangelista'], ['Kiara', 'Austria'], ['Leonardo', 'Campos'],
                    ['Mariel', 'Sarmiento'], ['Nathan', 'Belen'], ['Olivia', 'Alcantara'], ['Patrick', 'Serrano'],
                    ['Regina', 'Magbanua'], ['Samuel', 'Pangilinan'],
                ];
                foreach ($reviewNames as $index => [$firstName, $surname]) {
                    $rejected = $index < 10;
                    $student = $this->account('review-'.($index + 1), $firstName.' '.$surname, UserRole::Student);
                    $student->studentProfile()->firstOrCreate([], ['first_name' => $firstName, 'surname' => $surname,
                        'school' => 'Hiraya Learning College', 'course' => 'General Studies', 'year_level' => ($index % 4) + 1]);
                    $scholarship = Scholarship::withTrashed()->where('slug', 'hiraya-local-passers-'.(($index % 10) + 1))->firstOrFail();
                    $submitted = $today->copy()->subMonths(11)->subDays(1 + $index)->setTime(10, 0)->utc();
                    $application = Application::withTrashed()->firstOrCreate(['student_id' => $student->id, 'scholarship_id' => $scholarship->id],
                        ['academic_term_id' => $term->id, 'status' => $rejected ? 'rejected' : 'submitted',
                            'submitted_at' => $submitted, 'reviewed_by' => $rejected ? $provider->id : null,
                            'reviewed_at' => $rejected ? $submitted->copy()->addDays(3) : null,
                            'decision_reason' => $rejected ? [
                                'The submitted academic record did not meet the scholarship minimum grade requirement.',
                                'The required enrollment evidence was not supplied before the application deadline.',
                                'The applicant did not meet the program-specific eligibility criteria.',
                            ][$index % 3] : null]);
                    if ($application->wasRecentlyCreated) {
                        $student->activityLogs()->create(['action' => 'fixture.application_review_created', 'subject_type' => Application::class,
                            'subject_id' => $application->id, 'changes' => ['development_only' => true, 'fictional' => true,
                                'reason' => 'Synthetic application review record; not an actual scholarship decision.'], 'occurred_at' => now()]);
                    }
                }
            });
        });
        $this->command?->info('50 fictional passers, 10 rejected applications and 8 applications awaiting review are stored. Existing records and edits were preserved.');
    }

    private function account(string $key, string $name, UserRole $role): User
    {
        $email = 'passers.fixture.'.$key.'@example.com';
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($user->role !== $role || ! ActivityLog::where('action', 'fixture.passer_account_created')->where('subject_type', User::class)->where('subject_id', $user->id)->exists()) {
                throw new RuntimeException('Reserved fixture email conflicts with an existing account: '.$email);
            }

            return $user;
        }
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => $email, 'role' => $role, 'password' => bin2hex(random_bytes(32)),
        ])->save();
        $user->activityLogs()->create(['action' => 'fixture.passer_account_created', 'subject_type' => User::class, 'subject_id' => $user->id,
            'changes' => ['development_only' => true, 'fictional' => true], 'occurred_at' => now()]);

        return $user;
    }
}
