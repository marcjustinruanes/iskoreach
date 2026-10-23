<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcademicTermManagement
{
    public const REFERENCE_TABLES = ['scholarships', 'applications', 'scholarship_grants', 'renewals', 'payments'];

    public function version(AcademicTerm $term): string
    {
        return hash_hmac('sha256', json_encode([$term->id, $this->values($term), $term->getRawOriginal('updated_at')]), config('app.key'));
    }

    public function values(AcademicTerm $term): array
    {
        return ['academic_year' => $term->academic_year, 'semester' => $term->semester,
            'starts_on' => $term->starts_on?->toDateString(), 'ends_on' => $term->ends_on?->toDateString()];
    }

    public function referenced(AcademicTerm $term): bool
    {
        foreach (self::REFERENCE_TABLES as $table) {
            if (DB::table($table)->where('academic_term_id', $term->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function save(User $actor, array $data, ?AcademicTerm $term = null): bool
    {
        Gate::forUser($actor)->authorize($term ? 'update' : 'create', $term ?? AcademicTerm::class);
        try {
            return DB::transaction(function () use ($actor, $data, $term): bool {
                $item = $term ? AcademicTerm::query()->lockForUpdate()->findOrFail($term->id) : new AcademicTerm;
                if ($term) {
                    if (! hash_equals($this->version($item), $data['version'])) {
                        throw ValidationException::withMessages(['version' => 'This term changed. Reload the list and review the latest details.']);
                    }
                    if ($this->referenced($item)) {
                        throw ValidationException::withMessages(['term' => 'This term is in use and is read-only, including references from retained records.']);
                    }
                }
                $before = $term ? $this->values($item) : null;
                $item->fill(collect($data)->only(['academic_year', 'semester', 'starts_on', 'ends_on'])->all());
                if ($term && ! $item->isDirty()) {
                    return false;
                }
                $item->save();
                $actor->activityLogs()->create(['action' => $term ? 'academic_term.updated' : 'academic_term.created',
                    'subject_type' => AcademicTerm::class, 'subject_id' => $item->id,
                    'changes' => ['before' => $before, 'after' => $this->values($item)], 'occurred_at' => now()]);

                return true;
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['academic_year' => 'This academic year and semester already exist.']);
        }
    }
}
