<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only queue visibility for the dashboard, backed by the database queue's
 * own tables (authoritative). All methods degrade to safe defaults if the
 * tables are absent or the DB is briefly unavailable.
 */
class QueueInspector
{
    public function __construct(
        private DatabaseManager $db,
        private array $config,
    ) {
    }

    private function jobsTable(): string
    {
        $conn = $this->config['queue']['connection'] ?? config('queue.default');

        return (string) config("queue.connections.{$conn}.table", 'jobs');
    }

    /** @return array<string,int> pending job count per queue */
    public function pendingByQueue(): array
    {
        $table = $this->jobsTable();

        try {
            if (! Schema::hasTable($table)) {
                return [];
            }

            return $this->db->table($table)
                ->selectRaw('queue, COUNT(*) as aggregate')
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(static fn ($n) => (int) $n)
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    public function pendingTotal(): int
    {
        return array_sum($this->pendingByQueue());
    }

    /** Jobs currently reserved by a worker (in-flight). */
    public function reservedTotal(): int
    {
        $table = $this->jobsTable();

        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return (int) $this->db->table($table)->whereNotNull('reserved_at')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function failedTotal(): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }

            return (int) $this->db->table('failed_jobs')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int,object> recent failed jobs for display */
    public function recentFailed(int $limit = 50): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return [];
            }

            return $this->db->table('failed_jobs')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['uuid', 'id', 'connection', 'queue', 'exception', 'failed_at'])
                ->map(function ($row) {
                    $row->exception_summary = $this->summariseException((string) $row->exception);

                    return $row;
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function summariseException(string $exception): string
    {
        $firstLine = strtok($exception, "\n") ?: $exception;

        return mb_strimwidth(trim($firstLine), 0, 180, '…');
    }
}
