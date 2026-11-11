<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MailMonitorInspector
{
    public function __construct(private DatabaseManager $db)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('runtime-manager.mail_monitor.enabled', false);
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('runtime_mail_log');
        } catch (Throwable) {
            return false;
        }
    }

    public function sentLastDay(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return (int) $this->db->table('runtime_mail_log')->where('created_at', '>=', now()->subDay())->count();
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
            return $this->db->table('runtime_mail_log')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
