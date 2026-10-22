<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExternalApplicantImportController extends Controller
{
    public function __invoke(Request $request, Scholarship $scholarship): RedirectResponse
    {
        Gate::authorize('reportExternalCount', $scholarship);
        $data = $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'source_label' => ['required', 'string', 'max:200'], 'source_as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'submission_token' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $header = is_array($header) ? array_map(fn ($value): string => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\r\n")), $header) : [];
            $column = array_search('email', $header, true);
            if ($column === false || count(array_keys($header, 'email', true)) !== 1) {
                throw ValidationException::withMessages(['file' => 'Use a UTF-8 CSV with exactly one email column named email.']);
            }
            $identities = [];
            $rows = 0;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $email = strtolower(trim($row[$column] ?? ''));
                if (++$rows > 10000 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                    throw ValidationException::withMessages(['file' => 'CSV must contain at most 10,000 nonempty rows with valid email addresses. Invalid row: '.($rows + 1).'.']);
                }
                $identities[hash_hmac('sha256', $email, config('app.key'))] = true;
            }
        } finally {
            fclose($handle);
        }
        DB::transaction(function () use ($request, $scholarship, $data, $identities): void {
            ProviderProfile::whereKey($scholarship->provider_id)->lockForUpdate()->firstOrFail();
            Gate::authorize('reportExternalCount', $scholarship->fresh());
            $program = Scholarship::whereKey($scholarship->id)->lockForUpdate()->firstOrFail();
            $internal = $program->applications()->whereNotNull('submitted_at')->whereIn('status', ApplicationStatus::countedValues())->whereHas('student', fn ($query) => $query->where('role', 'student'))->with('student:id,email')->get();
            $external = $identities;
            foreach ($internal as $application) {
                unset($external[hash_hmac('sha256', strtolower(trim($application->student->email)), config('app.key'))]);
            }
            $count = count($external);
            $existing = ExternalApplicantCount::where('submission_token', $data['submission_token'])->first();
            if ($existing) {
                if ($existing->scholarship_id !== $program->id || $existing->reported_by !== $request->user()->id || $existing->source_label !== $data['source_label'] || $existing->source_as_of->toDateString() !== $data['source_as_of'] || $existing->reported_count !== $count) {
                    throw ValidationException::withMessages(['submission_token' => 'This token was used for a different snapshot. Reload before importing again.']);
                }

                return;
            }
            $report = new ExternalApplicantCount;
            $report->forceFill(['scholarship_id' => $program->id, 'reported_by' => $request->user()->id, 'reported_count' => $count, 'source_label' => $data['source_label'],
                'source_as_of' => $data['source_as_of'], 'duplicates_excluded' => true, 'submission_token' => $data['submission_token'], 'status' => 'pending'])->save();
            $request->user()->activityLogs()->create(['action' => 'external_count.reported', 'subject_type' => ExternalApplicantCount::class, 'subject_id' => $report->id,
                'changes' => ['scholarship_id' => $program->id, 'reported_count' => $count, 'source_as_of' => $data['source_as_of'], 'method' => 'csv_import'], 'occurred_at' => now()]);
        });

        return back()->with('status', 'CSV count imported for administrator verification. Duplicate email addresses and matching internal applicants were excluded. The uploaded email list was not retained.');
    }
}
