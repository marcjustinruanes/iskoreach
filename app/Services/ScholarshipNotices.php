<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Scholarship;
use App\Models\User;
use App\Notifications\ScholarshipNotice;
use Illuminate\Support\Facades\DB;

class ScholarshipNotices
{
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
