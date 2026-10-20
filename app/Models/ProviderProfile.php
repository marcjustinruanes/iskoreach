<?php

namespace App\Models;

use App\Enums\ProviderVerificationStatus;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_name', 'contact_email'])]
class ProviderProfile extends Model
{
    /** @use HasFactory<ProviderProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
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
