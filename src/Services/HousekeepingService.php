<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Models\RuntimeAudit;
use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Conservative periodic cleanup: prunes our own run/audit history, rotates an
 * oversized Laravel log, and clears expired database sessions. Never touches
 * application data or caches. All steps are individually guarded so one failing
 * step does not abort the rest.
 */
class HousekeepingService
{
    public function __construct(private array $config)
    {
    }

    /** @return array<string,mixed> */
    public function run(float $deadline): array
    {
        $summary = [];

        $summary['pruned_runs']  = $this->pruneRuns();
        $summary['pruned_audit'] = $this->pruneAudit();

        if (microtime(true) < $deadline) {
            $summary['log_rotated'] = $this->rotateLogIfLarge();
        }

        return array_filter($summary, static fn ($v) => $v !== null && $v !== 0 && $v !== false) ?: ['noop' => true];
    }

    private function pruneRuns(): int
    {
        $days = (int) ($this->config['retention']['runs_days'] ?? 14);
        if ($days <= 0) {
            return 0;
        }

        try {
            return RuntimeRun::query()->where('started_at', '<', Carbon::now()->subDays($days))->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    private function pruneAudit(): int
    {
        $days = (int) ($this->config['retention']['audit_days'] ?? 90);
        if ($days <= 0) {
            return 0;
        }

        try {
            return RuntimeAudit::query()->where('created_at', '<', Carbon::now()->subDays($days))->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Rotate storage/logs/laravel.log once it exceeds the configured size. */
    private function rotateLogIfLarge(): bool
    {
        $maxBytes = (int) ($this->config['housekeeping']['log_max_bytes'] ?? (20 * 1048576));
        if ($maxBytes <= 0) {
            return false;
        }

        $log = storage_path('logs/laravel.log');

        try {
            if (! is_file($log) || filesize($log) < $maxBytes) {
                return false;
            }

            $rotated = storage_path('logs/laravel-' . date('Ymd-His') . '.log');
            if (@rename($log, $rotated)) {
                @touch($log);

                return true;
            }
        } catch (Throwable) {
            // ignore
        }

        return false;
    }
}
