<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Contracts\RuntimeService;
use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use Cyberwizard\RuntimeManager\Runner\ServiceResult;
use Cyberwizard\RuntimeManager\Runner\Tick;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TickPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function tick(array $services): Tick
    {
        return new Tick($this->app->make(CacheFactory::class), (array) config('runtime-manager'), $services);
    }

    public function test_pipeline_runs_enabled_services_and_records_outcomes(): void
    {
        $ran = [];

        $ok = $this->fake('alpha', true, fn () => new ServiceResult(ServiceResult::OK, ['did' => 'work']), $ran);
        $off = $this->fake('beta', false, fn () => new ServiceResult(ServiceResult::OK), $ran);

        $result = $this->tick([$ok, $off])->run();

        $this->assertSame(RuntimeRun::SUCCESS, $result['status']);
        $this->assertContains('alpha', $ran, 'enabled service should run');
        $this->assertNotContains('beta', $ran, 'disabled service should not run');
        $this->assertSame('skipped', $result['services']['beta']['status']);
        $this->assertSame('work', $result['services']['alpha']['summary']['did']);

        $state = RuntimeState::singleton();
        $this->assertSame(1, $state->total_ticks);
        $this->assertNotNull($state->last_success_at);
    }

    public function test_a_throwing_service_yields_partial_not_fatal(): void
    {
        $ran = [];
        $ok = $this->fake('alpha', true, fn () => new ServiceResult(ServiceResult::OK), $ran);
        $boom = $this->fake('beta', true, function () {
            throw new \RuntimeException('kaboom');
        }, $ran);

        $result = $this->tick([$ok, $boom])->run();

        $this->assertSame(RuntimeRun::PARTIAL, $result['status']);
        $this->assertSame('failed', $result['services']['beta']['status']);
        $this->assertSame('kaboom', $result['services']['beta']['error']);
        // consecutive_failures only increments on a hard FAILED tick, not PARTIAL.
        $this->assertSame(0, RuntimeState::singleton()->consecutive_failures);
    }

    private function fake(string $key, bool $enabled, \Closure $run, array &$ran): RuntimeService
    {
        return new class($key, $enabled, $run, $ran) implements RuntimeService {
            public function __construct(
                private string $k,
                private bool $on,
                private \Closure $fn,
                private array &$ran,
            ) {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function isEnabled(): bool
            {
                return $this->on;
            }

            public function run(ExecutionBudget $budget): ServiceResult
            {
                $this->ran[] = $this->k;

                return ($this->fn)();
            }
        };
    }
}
