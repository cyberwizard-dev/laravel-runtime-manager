<?php

namespace Cyberwizard\RuntimeManager\Runner;

/**
 * Outcome of one service within a tick. Stored (as array) in the run record's
 * `services` column and surfaced on the dashboard.
 */
class ServiceResult
{
    public const OK      = 'ok';
    public const PARTIAL = 'partial';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(
        public string $status = self::OK,
        public array $summary = [],
        public ?string $error = null,
        public int $durationMs = 0,
    ) {
    }

    public static function skipped(string $reason = ''): self
    {
        return new self(self::SKIPPED, $reason !== '' ? ['reason' => $reason] : []);
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, [], $error);
    }

    public function failedStatus(): bool
    {
        return $this->status === self::FAILED;
    }

    public function toArray(): array
    {
        return array_filter([
            'status'      => $this->status,
            'summary'     => $this->summary ?: null,
            'error'       => $this->error,
            'duration_ms' => $this->durationMs,
        ], static fn ($v) => $v !== null);
    }
}
