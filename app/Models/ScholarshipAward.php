<?php

namespace App\Models;

use App\Enums\AwardStatus;
use App\Enums\ScholarshipStatus;
use App\Enums\UserRole;
use Database\Factories\ScholarshipAwardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'academic_term_id', 'status', 'starts_on', 'ends_on', 'revoked_at'])]
class ScholarshipAward extends Model
{
    /** @use HasFactory<ScholarshipAwardFactory> */
    use HasFactory;

    protected $table = 'scholarship_grants';

    protected function casts(): array
    {
        return ['workflow_version' => 'integer', 'history' => 'array', 'status' => AwardStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'revoked_at' => 'datetime', 'public_listing_consented_at' => 'datetime', 'published_at' => 'datetime', 'publication_version' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Renewal::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function scopeApprovedProvider(Builder $query): Builder
    {
        return $query->whereHas('application.scholarship.provider', fn (Builder $query) => $query
            ->where('verification_status', 'approved')->whereHas('user', fn (Builder $query) => $query->where('role', UserRole::Provider)));
    }

    public function scopeForAcademicTerm(Builder $query, ?int $termId, string $type = ''): Builder
    {
        return $query->where(function (Builder $query) use ($termId, $type): void {
            if ($type !== 'renewal') {
                $query->where(fn (Builder $query) => $query->when($termId, fn (Builder $query) => $query->where('academic_term_id', $termId)));
            }
            if ($type !== 'new' && ($termId !== null || $type === 'renewal')) {
                $method = $type === 'renewal' ? 'whereHas' : 'orWhereHas';
                $query->$method('renewals', fn (Builder $query) => $query->where('status', 'approved')->whereNotNull('reviewed_at')
                    ->when($termId, fn (Builder $query) => $query->where('academic_term_id', $termId)));
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now('Asia/Manila')->toDateString();

        return $query->where('scholarship_grants.status', AwardStatus::Active)->whereNull('scholarship_grants.revoked_at')
            ->whereDate('scholarship_grants.starts_on', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('scholarship_grants.ends_on')->orWhereDate('scholarship_grants.ends_on', '>=', $today))
            ->whereHas('application', fn (Builder $query) => $query->where('status', 'accepted')->whereNotNull('submitted_at')
                ->whereHas('student', fn (Builder $query) => $query->where('role', UserRole::Student)));
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === AwardStatus::Active && $this->revoked_at === null
            && $this->starts_on->toDateString() <= now('Asia/Manila')->toDateString()
            && ($this->ends_on === null || $this->ends_on->toDateString() >= now('Asia/Manila')->toDateString())
            && $this->application->status->value === 'accepted' && $this->application->submitted_at !== null
            && $this->application->student?->role === UserRole::Student;
    }

    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->active()->whereNotNull('public_listing_consented_at')->whereNotNull('published_at')->whereNotNull('public_name')
            ->whereHas('application', fn (Builder $query) => $query->whereNull('deleted_at')
                ->whereHas('scholarship', fn (Builder $query) => $query->whereNull('deleted_at')->where('status', ScholarshipStatus::Published)->whereNotNull('published_at')
                    ->whereHas('provider', fn (Builder $query) => $query->where('verification_status', 'approved')->whereHas('user', fn (Builder $query) => $query->where('role', UserRole::Provider)))));
    }

    public function canBePubliclyListed(): bool
    {
        return $this->isCurrentlyActive() && ! $this->application->trashed() && ! $this->application->scholarship->trashed()
            && $this->application->scholarship->status === ScholarshipStatus::Published
            && $this->application->scholarship->published_at !== null
            && $this->application->scholarship->provider->verification_status->value === 'approved'
            && $this->application->scholarship->provider->user?->role === UserRole::Provider
            && $this->public_listing_consented_at !== null;
    }
}
