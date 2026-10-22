<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\BenefitRelease;
use App\Models\ExternalApplicantCount;
use App\Models\ProviderProfile;
use App\Models\Renewal;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\ScholarshipDeclaration;
use App\Models\StudentDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdminAuditHistory
{
    public const TYPES = ['declaration' => ScholarshipDeclaration::class, 'application_document' => ApplicationDocument::class, 'academic_term' => AcademicTerm::class, 'provider' => ProviderProfile::class, 'external_count' => ExternalApplicantCount::class, 'award' => ScholarshipAward::class, 'account' => User::class, 'scholarship' => Scholarship::class, 'application' => Application::class, 'document' => StudentDocument::class, 'renewal' => Renewal::class, 'release' => BenefitRelease::class];

    public const ACTIONS = [
        'academic_term.created' => ['Academic term created', 'academic_term'],
        'academic_term.updated' => ['Academic term updated', 'academic_term'],
        'award.created' => ['Confirmed award recorded', 'award'],
        'award.completed' => ['Award completed', 'award'],
        'declaration.reviewed' => ['Outside scholarship reviewed', 'declaration'],
        'application.document_reviewed' => ['Application document reviewed', 'application_document'],
        'award.suspended' => ['Award suspended', 'award'],
        'award.resumed' => ['Award resumed', 'award'],
        'release.delayed' => ['Benefit delay recorded', 'release'],
        'release.failed' => ['Benefit failure recorded', 'release'],
        'award.revoked' => ['Award revoked', 'award'],
        'release.created' => ['Benefit record created', 'release'],
        'release.processing' => ['Benefit processing recorded', 'release'],
        'release.released' => ['Benefit release recorded', 'release'],
        'renewal.opened' => ['Renewal request opened', 'renewal'],
        'renewal.attach' => ['Renewal document attached', 'renewal'],
        'renewal.remove' => ['Draft renewal document removed', 'renewal'],
        'renewal.submit' => ['Renewal submitted', 'renewal'],
        'renewal.approved' => ['Renewal approved', 'renewal'],
        'renewal.rejected' => ['Renewal rejected', 'renewal'],
        'document.uploaded' => ['Private vault document uploaded', 'document'],
        'document.removed' => ['Vault document entry removed', 'document'],
        'application.document_attached' => ['Application document attached', 'application'],
        'application.document_removed' => ['Draft application document removed', 'application'],
        'application.draft_created' => ['Application draft prepared', 'application'],
        'application.submitted' => ['Application submitted', 'application'],
        'application.reviewed' => ['Application review saved', 'application'],
        'scholarship.created' => ['Scholarship draft created', 'scholarship'],
        'scholarship.updated' => ['Scholarship content updated', 'scholarship'],
        'scholarship.publish' => ['Scholarship published', 'scholarship'],
        'scholarship.unpublish' => ['Scholarship returned to draft', 'scholarship'],
        'scholarship.close' => ['Scholarship closed', 'scholarship'],
        'scholarship.archive' => ['Scholarship archived', 'scholarship'],
        'scholarship.remove' => ['Scholarship retained after removal', 'scholarship'],
        'admin.created_locally' => ['Administrator provisioned locally', 'account'],
        'provider.reviewed' => ['Provider review saved', 'provider'],
        'provider.details_changed' => ['Organization details changed', 'provider'],
        'external_count.reported' => ['External count reported', 'external_count'],
        'external_count.approve' => ['External count verified', 'external_count'],
        'external_count.reject' => ['External count rejected', 'external_count'],
        'external_count.correct' => ['External count corrected', 'external_count'],
        'external_count.reverify' => ['External count re-verified', 'external_count'],
        'award.publish' => ['Award published', 'award'],
        'award.unpublish' => ['Award listing removed', 'award'],
        'award.consent_given' => ['Public listing consent recorded', 'award'],
        'award.consent_withdrawn' => ['Public listing consent withdrawn', 'award'],
    ];

    public function query(array $filters): Builder
    {
        $query = ActivityLog::query()->where(function (Builder $query): void {
            foreach (self::ACTIONS as $action => [$label, $type]) {
                $query->orWhere(fn (Builder $query) => $query->where('action', $action)->where('subject_type', self::TYPES[$type]));
            }
        });
        foreach (['action', 'actor' => 'actor_id', 'record' => 'subject_id'] as $key => $column) {
            if (is_int($key)) {
                $key = $column;
            }
            if ($filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['type'] !== '') {
            $query->where('subject_type', self::TYPES[$filters['type']]);
        }
        if ($filters['from'] !== '') {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($filters['to'] !== '') {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    public function row(ActivityLog $log): array
    {
        [$label, $type] = self::ACTIONS[$log->action];
        $actor = $log->actor;
        $reasonAllowed = str_starts_with($log->action, 'scholarship.') || in_array($log->action, ['provider.reviewed', 'external_count.approve', 'external_count.reject', 'external_count.correct', 'external_count.reverify', 'award.publish', 'award.unpublish'], true);
        $changes = $log->changes ?? [];
        $reason = $reasonAllowed && is_string($changes['reason'] ?? null) ? mb_substr($changes['reason'], 0, 2000) : null;
        $transition = [];
        foreach (['from', 'to'] as $key) {
            if (in_array($changes[$key] ?? null, ['pending', 'approved', 'rejected'], true)) {
                $transition[$key] = $changes[$key];
            }
        }

        return ['id' => $log->id, 'actor' => ! $actor ? 'Unavailable actor' : ($actor->role->value === 'student' ? 'Student account #'.$actor->id : $actor->name),
            'actor_reference' => $actor ? 'Account #'.$actor->id : null, 'action' => $label, 'action_code' => $log->action,
            'record' => ucfirst(str_replace('_', ' ', $type)).' #'.$log->subject_id, 'occurred_at' => $log->occurred_at->toIso8601String(),
            'reason' => $reason, 'transition' => $transition];
    }
}
