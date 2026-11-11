<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Tests\Concerns\CreatesQueueTables;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HttpTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesQueueTables;

    private string $secret = 'topsecret-value';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('runtime-manager.http_trigger.enabled', true);
        $app['config']->set('runtime-manager.http_trigger.secret', $this->secret);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->get('/runtime/trigger')->assertStatus(401);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->get('/runtime/trigger', [
            'X-Runtime-Timestamp' => (string) time(),
            'X-Runtime-Signature' => 'deadbeef',
        ])->assertStatus(401);
    }

    public function test_valid_signature_runs_a_tick_and_blocks_replay(): void
    {
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts, $this->secret);
        $headers = ['X-Runtime-Timestamp' => $ts, 'X-Runtime-Signature' => $sig];

        $this->get('/runtime/trigger', $headers)
            ->assertOk()
            ->assertJsonStructure(['status', 'duration_ms']);

        $run = RuntimeRun::query()->latest('id')->first();
        $this->assertSame('http', $run->trigger);

        // Same signature again within the window → replay blocked.
        $this->get('/runtime/trigger', $headers)->assertStatus(409);
    }
}
