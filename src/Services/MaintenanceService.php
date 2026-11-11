<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Contracts\RuntimeService;
use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use Cyberwizard\RuntimeManager\Runner\ServiceResult;
use Throwable;

/**
 * The tick's final phase: housekeeping, a cache health probe, and the watchdog
 * evaluation. Kept lightweight and always last so it observes the outcome of
 * the scheduler and queue phases.
 */
class MaintenanceService implements RuntimeService
{
    public function __construct(
        private HousekeepingService $housekeeping,
        private CacheHealthService $cacheHealth,
        private WatchdogService $watchdog,
        private array $config,
    ) {
    }

    public function key(): string
    {
        return 'maintenance';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['maintenance']['enabled'] ?? true);
    }

    public function run(ExecutionBudget $budget): ServiceResult
    {
        $slice = $budget->allot($this->key());
        $deadline = microtime(true) + $slice;

        $summary = [];

        try {
            $summary['housekeeping'] = $this->housekeeping->run($deadline);
        } catch (Throwable $e) {
            $summary['housekeeping'] = ['error' => $e->getMessage()];
        }

        try {
            $summary['cache'] = $this->cacheHealth->check();
        } catch (Throwable $e) {
            $summary['cache'] = ['status' => 'fail', 'error' => $e->getMessage()];
        }

        try {
            $summary['watchdog'] = $this->watchdog->evaluate();
        } catch (Throwable $e) {
            $summary['watchdog'] = ['error' => $e->getMessage()];
        }

        $failed = ($summary['cache']['status'] ?? 'ok') === 'fail';

        return new ServiceResult(
            status: $failed ? ServiceResult::PARTIAL : ServiceResult::OK,
            summary: $summary,
        );
    }
}
