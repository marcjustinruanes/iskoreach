<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Scholarship;
use App\Services\ScholarshipNotices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('providers:remind')]
#[Description('Notify approved providers about approaching scholarship deadlines without duplicate reminders')]
class RemindProviderTasks extends Command
{
    public function handle(ScholarshipNotices $notices): int
    {
        $count = 0;
        Scholarship::where('status', 'published')->whereNotNull('published_at')->where('deadline_at', '>', now())
            ->whereHas('provider', fn ($query) => $query->where('verification_status', 'approved')->whereHas('user', fn ($query) => $query->where('role', 'provider')))
            ->eachById(function (Scholarship $program) use ($notices, &$count): void {
                DB::transaction(function () use ($program, $notices, &$count): void {
                    $item = Scholarship::lockForUpdate()->find($program->id);
                    if (! $item || $item->status->value !== 'published' || ! $item->provider->isApproved() || ! $item->deadline_at?->isFuture()) {
                        return;
                    }
                    $days = (int) now('Asia/Manila')->startOfDay()->diffInDays($item->deadline_at->copy()->timezone('Asia/Manila')->startOfDay(), false);
                    if (! in_array($days, [7, 3, 1], true)) {
                        return;
                    }
                    $key = $item->deadline_at->toIso8601String().':'.$days;
                    if (ActivityLog::where('subject_type', Scholarship::class)->where('subject_id', $item->id)->where('action', 'provider.deadline_reminded')->where('changes->key', $key)->exists()) {
                        return;
                    }
                    $notices->send($item->provider->user, $item, 'Scholarship deadline approaching', $item->title.': applications close in '.$days.' day(s). Review pending submissions.');
                    $item->provider->user->activityLogs()->create(['action' => 'provider.deadline_reminded', 'subject_type' => Scholarship::class, 'subject_id' => $item->id, 'changes' => ['key' => $key], 'occurred_at' => now()]);
                    $count++;
                });
            });
        $this->info($count.' provider deadline reminder(s) recorded.');

        return self::SUCCESS;
    }
}
