<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Scholarship;
use App\Models\ScholarshipDeclaration;
use App\Models\User;
use App\Notifications\ScholarshipNotice;
use Illuminate\Support\Facades\DB;

class ScholarshipNotices
{
    /** @return array<int, array{id: int, name: string, provider_name: string, starts_on: string, ends_on: ?string, verification: string, review_reason: ?string}> */
    public function declarations(User $student, Scholarship $scholarship, ?string $startsOn = null, ?string $endsOn = null): array
    {
        $query = ScholarshipDeclaration::where('student_id', $student->id);
        if ($startsOn === null) {
            $query->whereDate('starts_on', '<=', now('Asia/Manila')->toDateString());
        } elseif ($endsOn !== null) {
            $query->whereDate('starts_on', '<=', $endsOn);
        }
        $query->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn ?? now('Asia/Manila')->toDateString()));

        return $query->orderBy('id')->get()->map(function (ScholarshipDeclaration $item) use ($scholarship): array {
            $review = DB::table('scholarship_declaration_reviews')->where('scholarship_declaration_id', $item->id)->where('provider_id', $scholarship->provider_id)->first();

            return ['id' => $item->id, 'name' => $item->name, 'provider_name' => $item->provider_name,
                'starts_on' => $item->starts_on->toDateString(), 'ends_on' => $item->ends_on?->toDateString(),
                'verification' => $review?->status ?? 'self_reported', 'review_reason' => $review?->reason];
        })->all();
    }

    public function send(User $student, Scholarship $scholarship, string $title, string $message, ?Application $application = null): void
    {
        $student->notifyNow(new ScholarshipNotice($scholarship->id, $title, $message, $application?->id), ['database']);
        app(AccountNotifications::class)->changed($student);
    }

    public function reminders(): int
    {
        $count = 0;
        Application::whereNull('submitted_at')->whereHas('student', fn ($query) => $query->where('role', 'student'))->orderBy('id')->eachById(function (Application $application) use (&$count): void {
            DB::transaction(function () use ($application, &$count): void {
                $item = Application::query()->lockForUpdate()->findOrFail($application->id);
                $program = $item->scholarship;
                if ($item->submitted_at || ! $program || $program->trashed() || $program->status->value !== 'published' || ! $program->provider->isApproved() || ! $program->deadline_at?->isFuture()) {
                    return;
                }
                $days = (int) now('Asia/Manila')->startOfDay()->diffInDays($program->deadline_at->copy()->timezone('Asia/Manila')->startOfDay(), false);
                if (! in_array($days, [7, 3, 1], true)) {
                    return;
                }
                $key = $program->deadline_at->toIso8601String().':'.$days;
                $sent = $item->reminder_days ?? [];
                if (in_array($key, $sent, true)) {
                    return;
                }
                $missing = collect(app(PrivateDocuments::class)->checklist($item))->filter(fn (array $row): bool => $row['required'] && ! $row['complete'])->count();
                $this->send($item->student, $program, 'Application deadline and requirements', $program->title.': '.$days.' day(s) before the deadline; '.$missing.' required document(s) missing.', $item);
                $item->reminder_days = [...$sent, $key];
                $item->save();
                $count++;
            });
        });

        return $count;
    }
}
