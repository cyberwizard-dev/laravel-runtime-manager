<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HttpMonitorInspector
{
    public function __construct(private DatabaseManager $db)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('runtime-manager.http_monitor.enabled', false);
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('runtime_http_requests');
        } catch (Throwable) {
            return false;
        }
    }

    public function totalLastHour(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return (int) $this->db->table('runtime_http_requests')->where('created_at', '>=', now()->subHour())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function failuresLastHour(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return (int) $this->db->table('runtime_http_requests')
                ->where('created_at', '>=', now()->subHour())
                ->where('ok', false)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function avgDurationLastHour(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return (int) $this->db->table('runtime_http_requests')
                ->where('created_at', '>=', now()->subHour())
                ->avg('duration_ms');
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int,object> */
    public function recent(int $limit = 50): array
    {
        if (! $this->available()) {
            return [];
        }

        try {
            return $this->db->table('runtime_http_requests')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
