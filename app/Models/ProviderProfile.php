<?php

namespace App\Models;

use App\Enums\ProviderVerificationStatus;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_description', 'country', 'barangay_code', 'barangay_name', 'organization_name', 'contact_email', 'first_name', 'surname', 'middle_name', 'suffix', 'position', 'contact_number', 'work_email', 'organization_type', 'region_code', 'province_code', 'city_code', 'region_name', 'province_name', 'city_name', 'organization_phone', 'website', 'authorization_required'])]
class ProviderProfile extends Model
{
    /** @use HasFactory<ProviderProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'coverage_areas' => 'array',
            'registration_version' => 'integer', 'verification_documents' => 'array', 'authorization_required' => 'boolean',
            'verification_version' => 'integer',
            'verification_status' => ProviderVerificationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->verification_status === ProviderVerificationStatus::Approved;
    }

    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class, 'provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
