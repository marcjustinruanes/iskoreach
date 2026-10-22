<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\User;
use Database\Seeders\PublicPassersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class PublicPassersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_database_backed_passers_are_searchable_and_paginated_without_duplicates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        $existing = User::factory()->create();
        $this->seed(PublicPassersSeeder::class);
        $this->assertSame(50, ScholarshipAward::publiclyListed()->count());
        $this->assertSame(10, Scholarship::distinct()->count('scholarship_type'));
        $this->assertSame(50, ScholarshipAward::distinct()->count('published_at'));
        $this->assertSame(0, ScholarshipAward::whereLike('public_name', '%test%')->orWhereLike('public_name', '%demo%')->count());
        $this->get('/passers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('passers.total', 50)->has('passers.data', 12)->where('passers.last_page', 5));
        $this->get('/passers?q=Amelia%20Santos')->assertOk()->assertInertia(fn (Assert $page) => $page->where('passers.total', 1)->where('passers.data.0.name', 'Amelia Santos'));
        $this->get('/passers?year=2025')->assertOk()->assertInertia(fn (Assert $page) => $page->where('passers.total', 2));
        $award = ScholarshipAward::firstOrFail();
        $award->forceFill(['public_listing_consented_at' => null])->save();
        $this->seed(PublicPassersSeeder::class);
        $this->assertDatabaseCount('scholarship_awards', 50);
        $this->assertDatabaseCount('users', 70);
        $this->assertDatabaseCount('applications', 68);
        $this->assertDatabaseHas('users', ['name' => 'Alessandra Cabrera']);
        $this->assertSame(10, Application::where('status', 'rejected')->count());
        $this->assertSame(8, Application::where('status', 'submitted')->whereNull('reviewed_at')->count());
        $this->assertSame(0, ScholarshipAward::whereHas('application', fn ($query) => $query->whereIn('status', ['rejected', 'submitted']))->count());
        $this->assertDatabaseHas('users', ['id' => $existing->id]);
        $this->assertNull($award->fresh()->public_listing_consented_at);
        $this->assertSame(49, ScholarshipAward::publiclyListed()->count());
    }

    public function test_reserved_email_collision_rolls_back_without_overwriting_accounts(): void
    {
        $user = User::factory()->create(['email' => 'passers.fixture.student-1@example.com']);
        try {
            $this->seed(PublicPassersSeeder::class);
            $this->fail('Expected an account collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('scholarships', 0);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_production_environment_cannot_seed_fictional_passers(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        app(PublicPassersSeeder::class)->run();
    }
}
