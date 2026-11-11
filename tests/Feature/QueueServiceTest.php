<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Tests\Concerns\CreatesQueueTables;
use Cyberwizard\RuntimeManager\Tests\Fixtures\IncrementCacheJob;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class QueueServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesQueueTables;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
    }

    public function test_tick_processes_queued_jobs_through_the_database_driver(): void
    {
        Cache::forget(IncrementCacheJob::KEY);

        IncrementCacheJob::dispatch();
        IncrementCacheJob::dispatch();
        IncrementCacheJob::dispatch();

        $this->assertSame(3, Queue::size('default'), 'jobs should be queued before the tick');

        $this->artisan('runtime:tick')->assertSuccessful();

        $this->assertSame(3, (int) Cache::get(IncrementCacheJob::KEY), 'all jobs should have been processed');
        $this->assertSame(0, Queue::size('default'), 'queue should be drained');

        $run = RuntimeRun::query()->latest('id')->first();
        $this->assertSame(3, $run->services['queue']['summary']['processed'] ?? null);
    }
}
