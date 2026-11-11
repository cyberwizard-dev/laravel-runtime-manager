<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Single source of truth for application/runtime health. Consumed by the
 * dashboard overview and by the watchdog (which turns failing checks into
 * alerts). Every check is defensive: a probe that errors becomes a failing
 * check, never an exception that breaks the tick or the page.
 */
class HealthService
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        private Container $app,
        private CacheHealthService $cacheHealth,
        private QueueInspector $queues,
        private array $config,
    ) {
    }

    /** @return list<array{key:string,status:string,message:string}> */
    public function report(): array
    {
        return [
            $this->bootstrap(),
            $this->database(),
            $this->cache(),
            $this->storageWritable(),
            $this->cronLiveness(),
            $this->failedJobs(),
            $this->consecutiveFailures(),
            $this->disk(),
        ];
    }

    /** True when any check is failing. */
    public function hasFailures(): bool
    {
        foreach ($this->report() as $check) {
            if ($check['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{key:string,status:string,message:string}> only failing checks */
    public function failures(): array
    {
        return array_values(array_filter($this->report(), fn ($c) => $c['status'] === self::FAIL));
    }

    private function check(string $key, string $status, string $message): array
    {
        return ['key' => $key, 'status' => $status, 'message' => $message];
    }

    private function bootstrap(): array
    {
        return $this->check('bootstrap', self::OK, 'Application booted.');
    }

    private function database(): array
    {
        try {
            $this->app->make('db')->connection()->select('select 1');

            return $this->check('database', self::OK, 'Connection OK.');
        } catch (Throwable $e) {
            return $this->check('database', self::FAIL, 'Unreachable: ' . $e->getMessage());
        }
    }

    private function cache(): array
    {
        $result = $this->cacheHealth->check();

        return $result['status'] === 'ok'
            ? $this->check('cache', self::OK, 'Store "' . $result['store'] . '" OK.')
            : $this->check('cache', self::FAIL, 'Store "' . $result['store'] . '" failed: ' . ($result['error'] ?? 'unknown'));
    }

    private function storageWritable(): array
    {
        $paths = [storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')];
        $bad = array_filter($paths, fn ($p) => ! is_writable($p));

        return $bad === []
            ? $this->check('storage', self::OK, 'Writable.')
            : $this->check('storage', self::FAIL, 'Not writable: ' . implode(', ', $bad));
    }

    private function cronLiveness(): array
    {
        $staleAfter = (int) ($this->config['watchdog']['stale_after_seconds'] ?? 180);

        try {
            $state = RuntimeState::query()->find(1);
        } catch (Throwable) {
            return $this->check('cron', self::WARN, 'State unavailable.');
        }

        if (! $state || ! $state->last_tick_at) {
            return $this->check('cron', self::WARN, 'No tick recorded yet.');
        }

        $age = (int) $state->last_tick_at->diffInSeconds(now());

        return $age > $staleAfter
            ? $this->check('cron', self::FAIL, "No tick for {$age}s (threshold {$staleAfter}s). Cron may be down.")
            : $this->check('cron', self::OK, "Last tick {$age}s ago.");
    }

    private function failedJobs(): array
    {
        $count = $this->queues->failedTotal();
        $warnAt = (int) ($this->config['watchdog']['failed_jobs_warn'] ?? 1);

        if ($count === 0) {
            return $this->check('failed_jobs', self::OK, 'No failed jobs.');
        }

        return $this->check('failed_jobs', $count >= $warnAt ? self::WARN : self::OK, "{$count} failed job(s).");
    }

    private function consecutiveFailures(): array
    {
        try {
            $state = RuntimeState::query()->find(1);
            $n = $state?->consecutive_failures ?? 0;
        } catch (Throwable) {
            $n = 0;
        }

        if ($n === 0) {
            return $this->check('tick_failures', self::OK, 'No consecutive tick failures.');
        }

        return $this->check('tick_failures', $n >= 3 ? self::FAIL : self::WARN, "{$n} consecutive tick failure(s).");
    }

    private function disk(): array
    {
        try {
            $free = @disk_free_space(base_path());
        } catch (Throwable) {
            $free = false;
        }

        if ($free === false) {
            return $this->check('disk', self::WARN, 'Free space unknown.');
        }

        $mb = (int) ($free / 1048576);

        return $mb < 100
            ? $this->check('disk', self::FAIL, "Only {$mb}MB free.")
            : $this->check('disk', self::OK, "{$mb}MB free.");
    }
}
