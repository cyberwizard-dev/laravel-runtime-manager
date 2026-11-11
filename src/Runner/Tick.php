<?php

namespace Cyberwizard\RuntimeManager\Runner;

use Cyberwizard\RuntimeManager\Contracts\RuntimeService;
use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One runtime tick, end to end:
 *   acquire lock → open run → run each enabled service within its budget slice
 *   → close run → update heartbeat → release lock → optional external ping.
 *
 * Overlap is impossible: a tick that cannot take the lock records a `skipped`
 * run and returns without touching the heartbeat. A killed tick self-recovers
 * via the lock TTL and the database queue's own reservation timeout.
 */
class Tick
{
    /** @param iterable<RuntimeService> $services */
    public function __construct(
        private CacheFactory $cache,
        private array $config,
        private iterable $services = [],
    ) {
    }

    /** @return array{status:string,duration_ms:int,services:array,skipped?:bool} */
    public function run(string $trigger = 'cli'): array
    {
        if (! ($this->config['enabled'] ?? true)) {
            return ['status' => RuntimeRun::SKIPPED, 'duration_ms' => 0, 'services' => [], 'skipped' => true];
        }

        $lock = new LockManager($this->cache, (array) ($this->config['lock'] ?? []));

        if (! $lock->acquire()) {
            $this->recordSkipped($trigger);

            return ['status' => RuntimeRun::SKIPPED, 'duration_ms' => 0, 'services' => [], 'skipped' => true];
        }

        $startMs  = (int) (microtime(true) * 1000);
        $budget   = new ExecutionBudget((array) ($this->config['budget'] ?? []));
        $run      = $this->openRun($trigger);
        $services = [];
        $anyFailed = false;

        try {
            $this->touchState(fn (RuntimeState $s) => $s->forceFill([
                'current_phase' => 'running',
                'php_sapi'      => PHP_SAPI,
                'pid'           => getmypid() ?: null,
            ])->save());

            foreach ($this->services as $service) {
                $key = $service->key();

                if (! $service->isEnabled()) {
                    $services[$key] = ServiceResult::skipped('disabled')->toArray();
                    continue;
                }

                if ($budget->expired()) {
                    $services[$key] = ServiceResult::skipped('budget-exhausted')->toArray();
                    continue;
                }

                $this->touchState(fn (RuntimeState $s) => $s->forceFill(['current_phase' => $key])->save());

                $svcStart = microtime(true);
                try {
                    $result = $service->run($budget);
                } catch (Throwable $e) {
                    report($e);
                    $result = ServiceResult::failed($e->getMessage());
                }
                $result->durationMs = (int) ((microtime(true) - $svcStart) * 1000);

                $services[$key] = $result->toArray();
                $anyFailed = $anyFailed || $result->failedStatus();
            }
        } catch (Throwable $e) {
            report($e);
            $anyFailed = true;
            $services['_tick'] = ServiceResult::failed($e->getMessage())->toArray();
        }

        $durationMs = max(0, (int) (microtime(true) * 1000) - $startMs);
        $status = $anyFailed
            ? ($services === [] ? RuntimeRun::FAILED : RuntimeRun::PARTIAL)
            : RuntimeRun::SUCCESS;

        // A tick where the pipeline itself blew up (no service outcomes) is a hard failure.
        if ($anyFailed && count(array_filter($services, fn ($s) => ($s['status'] ?? null) !== ServiceResult::FAILED)) === 0) {
            $status = RuntimeRun::FAILED;
        }

        $this->closeRun($run, $status, $durationMs, $services);
        $this->finishState($status, $durationMs, $this->firstError($services));

        $lock->release();

        if ($status === RuntimeRun::SUCCESS || $status === RuntimeRun::PARTIAL) {
            $this->pingExternal($this->config['watchdog']['external_ping_url'] ?? null);
        }

        return ['status' => $status, 'duration_ms' => $durationMs, 'services' => $services];
    }

    private function openRun(string $trigger): ?RuntimeRun
    {
        try {
            return RuntimeRun::create([
                'started_at' => Carbon::now(),
                'status'     => RuntimeRun::SUCCESS,
                'trigger'    => $trigger === 'http' ? 'http' : 'cli',
            ]);
        } catch (Throwable $e) {
            $this->fileMarker('open-run-failed: ' . $e->getMessage());

            return null;
        }
    }

    private function closeRun(?RuntimeRun $run, string $status, int $durationMs, array $services): void
    {
        if ($run === null) {
            return;
        }

        try {
            $run->forceFill([
                'finished_at' => Carbon::now(),
                'duration_ms' => $durationMs,
                'status'      => $status,
                'services'    => $services ?: null,
                'error'       => $this->firstError($services),
            ])->save();
        } catch (Throwable $e) {
            $this->fileMarker('close-run-failed: ' . $e->getMessage());
        }
    }

    private function finishState(string $status, int $durationMs, ?string $error): void
    {
        $this->touchState(function (RuntimeState $state) use ($status, $durationMs, $error) {
            $failed = $status === RuntimeRun::FAILED;

            $state->forceFill([
                'last_tick_at'          => Carbon::now(),
                'last_tick_duration_ms' => $durationMs,
                'current_phase'         => 'idle',
                'consecutive_failures'  => $failed ? $state->consecutive_failures + 1 : 0,
                'total_ticks'           => $state->total_ticks + 1,
                'last_error'            => $error,
            ]);

            if (! $failed) {
                $state->last_success_at = Carbon::now();
            }

            $state->save();
        });
    }

    private function recordSkipped(string $trigger): void
    {
        try {
            RuntimeRun::create([
                'started_at'  => Carbon::now(),
                'finished_at' => Carbon::now(),
                'duration_ms' => 0,
                'status'      => RuntimeRun::SKIPPED,
                'trigger'     => $trigger === 'http' ? 'http' : 'cli',
            ]);
        } catch (Throwable) {
            // Non-fatal.
        }
    }

    private function firstError(array $services): ?string
    {
        foreach ($services as $outcome) {
            if (! empty($outcome['error'])) {
                return (string) $outcome['error'];
            }
        }

        return null;
    }

    private function touchState(callable $mutator): void
    {
        try {
            $mutator(RuntimeState::singleton());
        } catch (Throwable $e) {
            $this->fileMarker('state-write-failed: ' . $e->getMessage());
        }
    }

    private function pingExternal(?string $url): void
    {
        if (empty($url)) {
            return;
        }

        try {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3]]);
            @file_get_contents($url, false, $ctx);
        } catch (Throwable) {
            // Ignore — a failed ping must never fail a tick.
        }
    }

    private function fileMarker(string $message): void
    {
        try {
            $dir = storage_path('app/runtime');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir . '/last-error.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
        } catch (Throwable) {
            // Nothing more we can safely do.
        }
    }
}
