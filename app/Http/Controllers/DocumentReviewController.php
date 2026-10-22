<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\ScholarshipNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentReviewController extends Controller
{
    public function update(Request $request, ApplicationDocument $document, ScholarshipNotices $notices): RedirectResponse
    {
        Gate::authorize('review', $document->application);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($document, $data, $request, $notices): void {
            $application = Application::whereKey($document->application_id)->lockForUpdate()->firstOrFail();
            $item = ApplicationDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! $item->submitted_at || $item->removed_at || $application->scholarship->trashed() || ! in_array($application->status->value, ['submitted', 'under_review', 'waitlisted'], true)) {
                throw ValidationException::withMessages(['status' => 'Only submitted documents in an application awaiting a decision can be reviewed.']);
            }
            $item->forceFill(['review_status' => $data['status'], 'review_notes' => trim($data['reason']), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            $application->workflow_version++;
            $application->save();
            $request->user()->activityLogs()->create(['action' => 'application.document_reviewed', 'subject_type' => ApplicationDocument::class, 'subject_id' => $item->id, 'changes' => ['status' => $data['status'], 'reason' => $data['reason']], 'occurred_at' => now()]);
            $notices->send($application->student, $application->scholarship, 'Document review update', $application->scholarship->title.': '.$item->requirement_snapshot['name'].' '.$data['status'].'. '.$data['reason'], $application);
        });

        return back()->with('status', 'Document review saved.');
    }
}
