<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ProviderProfile;
use App\Models\Scholarship;
use App\Services\ExternalApplicants;
use App\Support\InputRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExternalApplicantImportController extends Controller
{
    public function __invoke(Request $request, Scholarship $scholarship, ExternalApplicants $applicants): RedirectResponse
    {
        Gate::authorize('reportExternalCount', $scholarship);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $stream = fopen($request->file('file')->getRealPath(), 'r');
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            $header = array_map(fn ($value): string => strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))), $header ?: []);
            if (! in_array('name', $header, true) || ! in_array('email', $header, true)) {
                throw ValidationException::withMessages(['file' => 'Use a CSV with name and email column headers.']);
            }
            $rows = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (count(array_filter($row, fn ($value): bool => trim((string) $value) !== ''))) {
                    $data = ['name' => trim($row[array_search('name', $header, true)] ?? ''), 'email' => strtolower(trim($row[array_search('email', $header, true)] ?? ''))];
                    $validator = Validator::make($data, ['name' => InputRules::name(), 'email' => InputRules::email()]);
                    if ($validator->fails()) {
                        throw ValidationException::withMessages(['file' => 'Every CSV row needs a valid name and email. Invalid row: '.(count($rows) + 2).'.']);
                    }
                    $rows[] = $data;
                    if (count($rows) > 2000) {
                        throw ValidationException::withMessages(['file' => 'Import at most 2,000 people at a time.']);
                    }
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV has no applicant records.']);
            }
        } finally {
            fclose($stream);
        }
        $saved = DB::transaction(function () use ($request, $scholarship, $applicants, $rows): int {
            ProviderProfile::whereKey($scholarship->provider_id)->lockForUpdate()->firstOrFail();
            Scholarship::whereKey($scholarship->id)->lockForUpdate()->firstOrFail();
            $saved = 0;
            foreach ($rows as $row) {
                if (Application::withTrashed()->where('scholarship_id', $scholarship->id)->whereRaw('LOWER(applicant_email) = ?', [$row['email']])->exists()) {
                    continue;
                }
                $applicants->store($request->user(), $scholarship, $row);
                $saved++;
            }

            return $saved;
        });

        return back()->with('status', 'Saved '.$saved.' external applicant records; skipped '.(count($rows) - $saved).' existing or duplicate people.');
    }
}
