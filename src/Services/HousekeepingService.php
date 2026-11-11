<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Models\RuntimeAudit;
use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $summary['pruned_http']  = $this->pruneHttpLog();
        $summary['gc_sessions']  = $this->gcDatabaseSessions();

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

    private function pruneHttpLog(): int
    {
        if (! (bool) config('runtime-manager.http_monitor.enabled', false)) {
            return 0;
        }

        $hours = (int) ($this->config['http_monitor']['retention_hours'] ?? 24);
        if ($hours <= 0) {
            return 0;
        }

        try {
            if (! Schema::hasTable('runtime_http_requests')) {
                return 0;
            }

            return DB::table('runtime_http_requests')
                ->where('created_at', '<', Carbon::now()->subHours($hours))
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Delete expired sessions when the application uses the database session driver. */
    private function gcDatabaseSessions(): int
    {
        if (! (bool) ($this->config['housekeeping']['gc_db_sessions'] ?? true)) {
            return 0;
        }

        if ((string) config('session.driver') !== 'database') {
            return 0;
        }

        $table = (string) config('session.table', 'sessions');
        $lifetime = (int) config('session.lifetime', 120); // minutes

        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            $cutoff = Carbon::now()->subMinutes($lifetime)->getTimestamp();
            $connection = config('session.connection');

            return DB::connection($connection)->table($table)
                ->where('last_activity', '<=', $cutoff)
                ->delete();
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
