<?php

namespace Database\Factories;

use App\Enums\ProviderVerificationStatus;
use App\Enums\UserRole;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderProfile> */
class ProviderProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Provider]),
            'organization_name' => fake()->company(),
            'contact_email' => fake()->safeEmail(),
            'verification_status' => ProviderVerificationStatus::Pending,
        ];
    }
}
