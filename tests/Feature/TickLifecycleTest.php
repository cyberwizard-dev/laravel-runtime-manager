<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Runner\LockManager;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TickLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tick_records_a_run_and_updates_the_heartbeat(): void
    {
        $this->artisan('runtime:tick')->assertSuccessful();

        $run = RuntimeRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(RuntimeRun::SUCCESS, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);

        $state = RuntimeState::singleton();
        $this->assertNotNull($state->last_tick_at);
        $this->assertNotNull($state->last_success_at);
        $this->assertSame(1, $state->total_ticks);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertSame('idle', $state->current_phase);
    }

    public function test_overlapping_tick_is_skipped_not_run(): void
    {
        // Hold the tick lock, then invoke a tick: it must skip cleanly.
        $lock = new LockManager($this->app->make(CacheFactory::class), (array) config('runtime-manager.lock'));
        $this->assertTrue($lock->acquire(), 'Pre-condition: first lock acquisition should succeed.');

        $this->artisan('runtime:tick')->assertSuccessful();

        $lock->release();

        $run = RuntimeRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(RuntimeRun::SKIPPED, $run->status);

        // Heartbeat must not advance on a skipped tick.
        $this->assertSame(0, RuntimeState::query()->count() === 0 ? 0 : RuntimeState::singleton()->total_ticks);
    }

    public function test_disabled_runtime_exits_without_running(): void
    {
        config(['runtime-manager.enabled' => false]);

        $this->artisan('runtime:tick')->assertSuccessful();

        $this->assertSame(0, RuntimeRun::query()->count());
    }
}
