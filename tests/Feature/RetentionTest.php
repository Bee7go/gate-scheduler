<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The retention windows documented in docs/DEPLOYMENT.md are enforced by
 * the scheduled model:prune run.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_runs_older_than_the_retention_window_are_prunable(): void
    {
        SyncRun::create(['trigger' => 'queue', 'status' => 'completed', 'started_at' => now()])
            ->forceFill(['created_at' => now()->subDays(91)])->save();
        SyncRun::create(['trigger' => 'queue', 'status' => 'completed', 'started_at' => now()]);

        $this->artisan('model:prune', ['--model' => [SyncRun::class]]);

        $this->assertSame(1, SyncRun::count());
        $this->assertTrue(SyncRun::first()->created_at->isToday());
    }

    public function test_expired_access_tokens_are_prunable_after_the_grace_period(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'Password123']);

        AccessToken::create(['user_id' => $user->id, 'token' => hash('sha256', 'old'), 'expires_at' => now()->subDays(8)]);
        AccessToken::create(['user_id' => $user->id, 'token' => hash('sha256', 'recent'), 'expires_at' => now()->subDay()]);
        AccessToken::create(['user_id' => $user->id, 'token' => hash('sha256', 'live'), 'expires_at' => now()->addHour()]);

        $this->artisan('model:prune', ['--model' => [AccessToken::class]]);

        // Only the token expired longer than the grace period is dropped.
        $this->assertSame(2, AccessToken::count());
    }
}
