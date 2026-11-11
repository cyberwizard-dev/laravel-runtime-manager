<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Tests\Concerns\CreatesQueueTables;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TokenTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesQueueTables;

    private string $token = 'super-long-unguessable-token-value-123456';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('runtime-manager.http_trigger.enabled', true);
        $app['config']->set('runtime-manager.http_trigger.token', $this->token);
        $app['config']->set('runtime-manager.http_trigger.secret', null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
    }

    public function test_valid_token_in_query_runs_a_tick(): void
    {
        $this->get('/runtime/trigger?token=' . $this->token)
            ->assertOk()
            ->assertJsonStructure(['status', 'duration_ms']);

        $this->assertSame('http', RuntimeRun::query()->latest('id')->first()->trigger);
    }

    public function test_valid_token_in_header_runs_a_tick(): void
    {
        $this->get('/runtime/trigger', ['X-Runtime-Token' => $this->token])->assertOk();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->get('/runtime/trigger?token=nope')->assertStatus(401);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->get('/runtime/trigger')->assertStatus(401);
    }
}
