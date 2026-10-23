<?php

namespace App\Services;

use App\Models\ScholarshipAward;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminReports
{
    public const DEFINITIONS = [
        'registrations' => ['title' => 'Account registrations', 'description' => 'Student and provider registrations by Philippine calendar date. Administrator accounts are excluded.', 'columns' => ['date' => 'Registration date', 'role' => 'Account type', 'count' => 'Registrations']],
        'providers' => ['title' => 'Provider verification', 'description' => 'Current verification status of provider accounts registered in the selected period, including accounts with missing profiles.', 'columns' => ['status' => 'Verification status', 'count' => 'Providers']],
        'scholarships' => ['title' => 'Scholarships by status', 'description' => 'Current publication status of scholarships created in the period. Retained records are shown separately.', 'columns' => ['status' => 'Publication status', 'visibility' => 'Record visibility', 'count' => 'Scholarships']],
        'locations' => ['title' => 'Scholarships by location', 'description' => 'Distinct scholarships created in the period by recorded location scope and codes. Multi-location scholarships can appear in more than one row; these rows must not be added as a platform total.', 'columns' => ['scope' => 'Scope', 'region' => 'Region code', 'city' => 'City code', 'count' => 'Distinct scholarships']],
        'applications' => ['title' => 'Submitted applications', 'description' => 'Applications submitted during the period, grouped by scholarship and current status. Includes waitlisted and retained submissions; excludes drafts and non-student records.', 'columns' => ['scholarship_id' => 'Scholarship number', 'scholarship' => 'Scholarship', 'provider' => 'Provider', 'status' => 'Application status', 'count' => 'Applications']],
        'active_awards' => ['title' => 'Active awards', 'description' => 'Awards created in the period that are currently active in Asia/Manila, backed by accepted submitted student applications. Accepted applications alone are not awards.', 'columns' => ['year' => 'Academic year', 'semester' => 'Semester', 'count' => 'Active awards']],
        'renewals' => ['title' => 'Renewal status', 'description' => 'Current status of renewal tracking records created in the selected period. Provider decisions remain with providers.', 'columns' => ['status' => 'Renewal status', 'count' => 'Renewals']],
        'releases' => ['title' => 'Benefit release status', 'description' => 'Release tracking records created in the period, grouped by current status and currency. Amounts in different currencies are never combined. Missing release timestamps are flagged.', 'columns' => ['status' => 'Release status', 'currency' => 'Currency', 'count' => 'Records', 'amount' => 'Recorded amount', 'incomplete' => 'Released records missing timestamp']],
    ];

    public function query(array $filters): Builder
    {
        $type = $filters['type'];
        $query = match ($type) {
            'registrations' => $this->registrations(),
            'providers' => DB::table('accounts')->leftJoin('provider_profiles', 'provider_profiles.user_id', '=', 'accounts.id')
                ->where('accounts.role', 'provider')->selectRaw("COALESCE(provider_profiles.verification_status, 'profile_missing') AS status, COUNT(*) AS count")->groupBy('provider_profiles.verification_status'),
            'scholarships' => DB::table('scholarships')->selectRaw("status, CASE WHEN deleted_at IS NULL THEN 'current' ELSE 'retained' END AS visibility, COUNT(*) AS count")->groupBy('status', 'visibility'),
            'locations' => DB::table('scholarships')->leftJoin('scholarship_locations', 'scholarship_locations.scholarship_id', '=', 'scholarships.id')
                ->selectRaw("COALESCE(scope, 'not_recorded') AS scope, COALESCE(region_code, 'Not recorded') AS region, COALESCE(city_code, 'Not recorded') AS city, COUNT(DISTINCT scholarships.id) AS count")
                ->groupBy('scope', 'region', 'city'),
            'applications' => DB::table('applications')->leftJoin('accounts', 'accounts.id', '=', 'applications.student_id')
                ->join('scholarships', 'scholarships.id', '=', 'applications.scholarship_id')->join('provider_profiles', 'provider_profiles.id', '=', 'scholarships.provider_id')
                ->where(fn ($query) => $query->where('accounts.role', 'student')->orWhere('applications.applicant_source', 'external'))->whereNotNull('submitted_at')->whereIn('applications.status', ['submitted', 'under_review', 'accepted', 'rejected', 'waitlisted'])
                ->selectRaw("scholarships.id AS scholarship_id, scholarships.title AS scholarship, COALESCE(provider_profiles.organization_name, 'Not recorded') AS provider, applications.status AS status, COUNT(*) AS count")
                ->groupBy('scholarships.id', 'scholarships.title', 'provider_profiles.organization_name', 'applications.status'),
            'active_awards' => ScholarshipAward::query()->active()->join('academic_terms', 'academic_terms.id', '=', 'scholarship_grants.academic_term_id')
                ->selectRaw('academic_year AS year, semester, COUNT(*) AS count')->groupBy('academic_year', 'semester')->toBase(),
            'renewals' => DB::table('renewals')->selectRaw('status, COUNT(*) AS count')->groupBy('status'),
            'releases' => DB::table('payments')->selectRaw("status, currency, COUNT(*) AS count, SUM(amount) AS amount, SUM(CASE WHEN status = 'released' AND released_at IS NULL THEN 1 ELSE 0 END) AS incomplete")->groupBy('status', 'currency'),
        };
        $column = match ($type) {
            'registrations', 'providers' => 'accounts.created_at',
            'applications' => 'applications.submitted_at',
            'active_awards' => 'scholarship_grants.created_at',
            'releases' => 'payments.created_at',
            'renewals' => 'renewals.created_at',
            default => 'scholarships.created_at',
        };
        $query->where($column, '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc())
            ->where($column, '<', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        foreach (array_keys(self::DEFINITIONS[$type]['columns']) as $key) {
            if (! in_array($key, ['count', 'amount', 'incomplete'], true)) {
                $query->orderBy($key);
            }
        }

        return $query;
    }

    public function row(object $row, string $type): array
    {
        $data = [];
        foreach (array_keys(self::DEFINITIONS[$type]['columns']) as $key) {
            $data[$key] = match ($key) {
                'count', 'incomplete', 'scholarship_id' => (int) $row->$key,
                'amount' => $row->$key === null ? 'Not recorded' : (is_string($row->$key) ? $row->$key : number_format((float) $row->$key, 2, '.', '')),
                default => (string) $row->$key,
            };
        }

        return $data;
    }

    public function csvCell(string|int $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }

    private function registrations(): Builder
    {
        $date = DB::connection()->getDriverName() === 'sqlite' ? "date(accounts.created_at, '+8 hours')" : 'DATE(DATE_ADD(accounts.created_at, INTERVAL 8 HOUR))';

        return DB::table('accounts')->whereIn('role', ['student', 'provider'])->selectRaw($date.' AS date, role, COUNT(*) AS count')->groupByRaw($date)->groupBy('role');
    }
}
