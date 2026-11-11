<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Contracts\RuntimeService;
use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use Cyberwizard\RuntimeManager\Runner\ServiceResult;
use Illuminate\Contracts\Console\Kernel;
use Throwable;

/**
 * Runs Laravel's native scheduler in-process, once per tick.
 *
 * We deliberately call `schedule:run` rather than re-implement scheduling, so
 * the app's existing tasks, frequencies, timezones, `withoutOverlapping` and
 * `onOneServer` behaviour are preserved exactly. Because the tick holds the
 * overlap lock and runs at most once per minute, `schedule:run` is invoked at
 * most once per minute — no duplicate scheduling.
 */
class SchedulerService implements RuntimeService
{
    public function __construct(
        private Kernel $kernel,
        private array $config,
    ) {
    }

    public function key(): string
    {
        return 'scheduler';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['scheduler']['enabled'] ?? true);
    }

    public function run(ExecutionBudget $budget): ServiceResult
    {
        // Reserve the scheduler's slice (keeps weighting fair for later services).
        $budget->allot($this->key());

        try {
            $code = $this->kernel->call('schedule:run');
            $output = trim((string) $this->kernel->output());

            $lines = $output === '' ? [] : explode("\n", $output);
            $noneDue = $output === '' || str_contains($output, 'No scheduled commands are ready');

            return new ServiceResult(
                status: $code === 0 ? ServiceResult::OK : ServiceResult::PARTIAL,
                summary: [
                    'ran'        => ! $noneDue,
                    'exit_code'  => $code,
                    'line_count' => count($lines),
                    'tail'       => array_slice($lines, -5),
                ],
            );
        } catch (Throwable $e) {
            return ServiceResult::failed($e->getMessage());
        }
    }
}
