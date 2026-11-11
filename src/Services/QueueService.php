<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Contracts\RuntimeService;
use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use Cyberwizard\RuntimeManager\Runner\ServiceResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Throwable;

/**
 * Processes queued jobs in short-lived, bounded `queue:work` cycles — Laravel's
 * own supported way to run a worker without a daemon.
 *
 * The queue's budget slice is divided across the configured queues in priority
 * order, each guaranteed a floor so a busy high-priority queue cannot starve
 * the lower ones. The database driver's atomic reservation already prevents a
 * job being processed twice across overlapping ticks, so we rely on it rather
 * than inventing our own de-duplication.
 */
class QueueService implements RuntimeService
{
    private int $processed = 0;
    private int $failed = 0;
    private bool $listening = false;

    public function __construct(
        private Kernel $kernel,
        private Dispatcher $events,
        private array $config,
    ) {
    }

    public function key(): string
    {
        return 'queue';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['queue']['enabled'] ?? true);
    }

    public function run(ExecutionBudget $budget): ServiceResult
    {
        $slice = $budget->allot($this->key());
        if ($slice < 1.0) {
            return ServiceResult::skipped('no-budget');
        }

        $queueConfig = (array) ($this->config['queue'] ?? []);
        $queues = array_values(array_filter((array) ($queueConfig['queues'] ?? ['default'])));
        if ($queues === []) {
            $queues = ['default'];
        }

        $connection = $queueConfig['connection'] ?? null;
        $defaults   = (array) ($queueConfig['defaults'] ?? []);
        $overrides  = (array) ($queueConfig['overrides'] ?? []);

        $this->startCounting();

        $perQueue = [];
        $deadline = microtime(true) + $slice;
        $remainingQueues = count($queues);

        foreach ($queues as $queue) {
            $left = $deadline - microtime(true);
            if ($left < 1.0) {
                $perQueue[$queue] = ['skipped' => 'no-budget'];
                $remainingQueues--;
                continue;
            }

            $opts  = array_merge($defaults, (array) ($overrides[$queue] ?? []));
            $floor = (float) ($opts['max_time'] ?? 10);
            // Guarantee each queue a fair, floor-backed share of what's left.
            $alloc = max(min($floor, $left), $left / max(1, $remainingQueues));
            $alloc = (int) floor(min($alloc, $left));
            $remainingQueues--;

            if ($alloc < 1) {
                $perQueue[$queue] = ['skipped' => 'no-budget'];
                continue;
            }

            $perQueue[$queue] = $this->workQueue($connection, $queue, $opts, $alloc);
        }

        return new ServiceResult(
            status: ServiceResult::OK,
            summary: [
                'processed' => $this->processed,
                'failed'    => $this->failed,
                'queues'    => $perQueue,
            ],
        );
    }

    /** Count jobs actually processed/failed this run via queue events (real counts). */
    private function startCounting(): void
    {
        if ($this->listening) {
            return;
        }

        $this->events->listen(JobProcessed::class, function () {
            $this->processed++;
        });
        $this->events->listen(JobFailed::class, function () {
            $this->failed++;
        });
        $this->listening = true;
    }

    private function workQueue(?string $connection, string $queue, array $opts, int $maxTime): array
    {
        $params = array_filter([
            'connection'        => $connection,
            '--queue'           => $queue,
            '--stop-when-empty' => true,
            '--max-time'        => $maxTime,
            '--max-jobs'        => (int) ($opts['max_jobs'] ?? 50),
            '--tries'           => (int) ($opts['tries'] ?? 3),
            '--backoff'         => (int) ($opts['backoff'] ?? 10),
            '--memory'          => (int) ($opts['memory'] ?? 96),
            '--sleep'           => (int) ($opts['sleep'] ?? 0),
        ], static fn ($v) => $v !== null && $v !== false);

        try {
            $start = microtime(true);
            $this->kernel->call('queue:work', $params);

            return ['max_time' => $maxTime, 'elapsed_ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }
    }
}
