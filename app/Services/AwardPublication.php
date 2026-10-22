<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScholarshipAward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AwardPublication
{
    public function update(User $actor, ScholarshipAward $award, array $data): void
    {
        DB::transaction(function () use ($actor, $award, $data): void {
            $locked = ScholarshipAward::query()->lockForUpdate()->findOrFail($award->id);
            $consent = array_key_exists('consent', $data);
            Gate::forUser($actor)->authorize($consent ? 'consent' : 'publish', $locked);
            if ($locked->publication_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This award changed. Reload the page before continuing.']);
            }
            $action = $consent ? ($data['consent'] ? 'consent_given' : 'consent_withdrawn') : $data['action'];
            if ($action === 'publish' && ! $locked->canBePubliclyListed()) {
                throw ValidationException::withMessages(['action' => 'Publication requires an active confirmed award, student consent, current records, a published scholarship, and an approved provider.']);
            }
            if ($action === 'consent_given') {
                if (! $locked->isCurrentlyActive()) {
                    throw ValidationException::withMessages(['consent' => 'Only a confirmed active award can be submitted for public listing.']);
                }
                $locked->public_listing_consented_at = now();
            } elseif ($action === 'consent_withdrawn') {
                $locked->public_listing_consented_at = null;
            }
            if ($action === 'publish') {
                $locked->public_name = $locked->application->student->name;
                $locked->published_at = now();
                $locked->published_by = $actor->id;
            } elseif (in_array($action, ['unpublish', 'consent_withdrawn'], true)) {
                $locked->public_name = null;
                $locked->published_at = null;
                $locked->published_by = null;
            }
            $locked->publication_version++;
            $locked->save();
            $log = new ActivityLog(['action' => 'award.'.$action, 'subject_type' => ScholarshipAward::class, 'subject_id' => $locked->id,
                'changes' => ['reason' => $data['reason'] ?? null, 'version' => $locked->publication_version], 'occurred_at' => now()]);
            $log->actor()->associate($actor);
            $log->save();
        });
    }
}
