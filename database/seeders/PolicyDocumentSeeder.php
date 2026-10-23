<?php

namespace Database\Seeders;

use App\Models\PolicyDocument;
use Illuminate\Database\Seeder;

class PolicyDocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (config('registration-policies') as $kind => $policy) {
            if (! PolicyDocument::query()->where('kind', $kind)->exists()) {
                PolicyDocument::query()->create(['kind' => $kind, ...$policy, 'published_at' => now()]);
            }
        }
    }
}
