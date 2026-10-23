<?php

namespace App\Services;

use App\Enums\ScholarshipStatus;
use App\Models\AcademicTerm;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScholarshipManagement
{
    public function save(User $user, array $data, ?Scholarship $scholarship = null): Scholarship
    {
        return DB::transaction(function () use ($user, $data, $scholarship): Scholarship {
            $profile = ProviderProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accessWorkspace', $profile);
            $item = $scholarship ? Scholarship::query()->lockForUpdate()->findOrFail($scholarship->id) : new Scholarship;
            Gate::forUser($user)->authorize($scholarship ? 'manage' : 'create', $scholarship ? $item : Scholarship::class);
            if ($scholarship) {
                $this->version($item, $data['version']);
                if ($item->status === ScholarshipStatus::Published || $item->applications()->whereNotNull('submitted_at')->exists()) {
                    throw ValidationException::withMessages(['title' => 'Content is locked while published or after a submitted application exists. Unpublish before editing; submitted application history permanently locks content.']);
                }
            } else {
                $item->provider_id = $profile->id;
                $item->slug = Str::slug($data['title']).'-'.Str::uuid();
                $item->status = ScholarshipStatus::Draft;
                $item->scholarship_type = '';
            }
            if (array_key_exists('academic_year', $data)) {
                if (empty($data['academic_year'])) {
                    $data['academic_term_id'] = null;
                } elseif ($item->academicTerm?->academic_year !== $data['academic_year']) {
                    $term = AcademicTerm::where('academic_year', $data['academic_year'])->orderBy('id')->lockForUpdate()->first();
                    if (! $term) {
                        [$startYear, $endYear] = explode('-', $data['academic_year']);
                        $term = AcademicTerm::firstOrCreate(['academic_year' => $data['academic_year'], 'semester' => 'Academic year'], [
                            'starts_on' => CarbonImmutable::create((int) $startYear, 1, 1)->toDateString(),
                            'ends_on' => CarbonImmutable::create((int) $endYear, 1, 1)->endOfYear()->toDateString(),
                        ]);
                    }
                    $data['academic_term_id'] = $term->id;
                } else {
                    $data['academic_term_id'] = $item->academic_term_id;
                }
            }
            if (! empty($data['academic_term_id'])) {
                AcademicTerm::query()->lockForUpdate()->findOrFail($data['academic_term_id']);
            }
            $item->fill(collect($data)->only(['title', 'description', 'scholarship_type', 'benefit_amount', 'currency', 'academic_term_id', 'accepts_existing_scholars', 'existing_scholars_low_priority'])->all());
            $item->scholarship_type ??= '';
            foreach (['opens_at', 'deadline_at'] as $field) {
                $item->$field = empty($data[$field]) ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$field], 'Asia/Manila')->utc();
            }
            $criteria = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['eligibility_criteria'] ?? ''))));
            foreach (['slots', 'waitlist_slots'] as $limit) {
                if (array_key_exists($limit, $data)) {
                    $data[$limit] = filled($data[$limit]) ? (int) $data[$limit] : null;
                }
            }
            $item->eligibility_rules = [...($item->eligibility_rules ?? []), 'criteria' => $criteria, 'provider_settings' => array_replace($item->eligibility_rules['provider_settings'] ?? [], collect($data)->only(['purpose', 'benefits_description', 'slots', 'waitlist_slots', 'priority_education_level', 'priority_courses', 'priority_year_levels'])->all(), ['application_method' => 'internal', 'external_application_url' => null])];
            $item->management_version = ($item->management_version ?? 0) + 1;
            $item->save();
            $item->locations()->delete();
            foreach ($data['locations'] as $location) {
                $item->locations()->create([...app(PhilippineLocations::class)->resolve($location), 'scope' => $location['scope']]);
            }
            $item->requirements()->delete();
            foreach ($data['requirements'] as $index => $requirement) {
                $item->requirements()->create([...$requirement, 'sort_order' => $index]);
            }
            $this->log($user, $item, $scholarship ? 'updated' : 'created', null);

            return $item;
        });
    }

    public function transition(User $user, Scholarship $scholarship, array $data): void
    {
        DB::transaction(function () use ($user, $scholarship, $data): void {
            $profile = ProviderProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('accessWorkspace', $profile);
            $item = Scholarship::query()->lockForUpdate()->findOrFail($scholarship->id);
            Gate::forUser($user)->authorize('manage', $item);
            $this->version($item, $data['version']);
            if ($data['action'] === 'publish') {
                if (! $item->opens_at || ! $item->deadline_at || $item->deadline_at->lte($item->opens_at) || $item->deadline_at->isPast() || ! $item->locations()->exists()) {
                    throw ValidationException::withMessages(['action' => 'Publishing requires opening and future deadline dates in order, and at least one location.']);
                }
                $item->status = ScholarshipStatus::Published;
                $item->published_at ??= now();
            } elseif ($data['action'] !== 'remove') {
                $item->status = match ($data['action']) {
                    'unpublish' => ScholarshipStatus::Draft, 'close' => ScholarshipStatus::Closed, 'archive' => ScholarshipStatus::Archived
                };
            }
            $item->management_version++;
            $item->save();
            if ($data['action'] === 'remove') {
                $item->delete();
            }
            $this->log($user, $item, $data['action'], $data['reason'] ?? null);
        });
    }

    public function formData(Scholarship $item): array
    {
        $item->load(['locations', 'requirements', 'academicTerm']);

        return [...$item->only('title', 'description', 'benefit_amount', 'currency', 'academic_term_id', 'accepts_existing_scholars', 'existing_scholars_low_priority'), 'academic_year' => $item->academicTerm?->academic_year,
            ...($item->eligibility_rules['provider_settings'] ?? []), 'version' => $item->management_version, 'eligibility_criteria' => implode("\n", array_filter(is_array($item->eligibility_rules['criteria'] ?? null) ? $item->eligibility_rules['criteria'] : [], 'is_string')),
            'opens_at' => $item->opens_at?->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'deadline_at' => $item->deadline_at?->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            'locations' => $item->locations->map(fn ($location): array => $location->only('scope', 'region_name', 'city_name', 'province_name')),
            'requirements' => $item->requirements->map(fn ($requirement): array => $requirement->only('name', 'description', 'required', 'requirement_stage', 'allowed_mime_types', 'max_size_kb'))];
    }

    public function updateCapacity(User $user, Scholarship $scholarship, array $data): void
    {
        DB::transaction(function () use ($user, $scholarship, $data): void {
            ProviderProfile::whereKey($scholarship->provider_id)->lockForUpdate()->firstOrFail();
            $item = Scholarship::lockForUpdate()->findOrFail($scholarship->id);
            Gate::forUser($user)->authorize('manage', $item);
            $this->version($item, $data['version']);
            $used = app(ScholarshipCapacity::class)->summary($item);
            if ($data['slots'] !== null && (int) $data['slots'] < $used['applications']) {
                throw ValidationException::withMessages(['slots' => 'Application slots cannot be below the number of submitted, under-review, and accepted applications.']);
            }
            if ($data['waitlist_slots'] !== null && (int) $data['waitlist_slots'] < $used['waitlisted']) {
                throw ValidationException::withMessages(['waitlist_slots' => 'Waitlist slots cannot be below the number of waitlisted applicants.']);
            }
            $rules = $item->eligibility_rules ?? [];
            $rules['provider_settings'] = array_replace($rules['provider_settings'] ?? [], ['slots' => $data['slots'] === null ? null : (int) $data['slots'], 'waitlist_slots' => $data['waitlist_slots'] === null ? null : (int) $data['waitlist_slots']]);
            $item->eligibility_rules = $rules;
            $item->management_version++;
            $item->save();
            $this->log($user, $item, 'capacity_updated', null);
        });
    }

    private function version(Scholarship $item, int $version): void
    {
        if ($item->management_version !== $version) {
            throw ValidationException::withMessages(['version' => 'This scholarship changed. Reload it before continuing.']);
        }
    }

    private function log(User $user, Scholarship $item, string $action, ?string $reason): void
    {
        $user->activityLogs()->create(['action' => 'scholarship.'.$action, 'subject_type' => Scholarship::class, 'subject_id' => $item->id,
            'changes' => ['reason' => $reason, 'status' => $item->status->value, 'version' => $item->management_version], 'occurred_at' => now()]);
    }
}
