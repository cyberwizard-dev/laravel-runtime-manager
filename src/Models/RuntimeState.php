<?php

namespace Cyberwizard\RuntimeManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton heartbeat row (id = 1). Written on every tick so the dashboard and
 * watchdog can see liveness, the current phase, and the last error.
 *
 * @property \Illuminate\Support\Carbon|null $last_tick_at
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property int|null $last_tick_duration_ms
 * @property string|null $current_phase
 * @property string|null $php_sapi
 * @property int|null $pid
 * @property int $consecutive_failures
 * @property int $total_ticks
 * @property string|null $last_error
 * @property array|null $meta
 */
class RuntimeState extends Model
{
    protected $table = 'runtime_state';

    protected $guarded = [];

    protected $casts = [
        'last_tick_at'          => 'datetime',
        'last_success_at'       => 'datetime',
        'last_tick_duration_ms' => 'integer',
        'pid'                   => 'integer',
        'consecutive_failures'  => 'integer',
        'total_ticks'           => 'integer',
        'meta'                  => 'array',
    ];

    /** The one and only state row, created on first access. */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /** Seconds since the last recorded tick, or null if it has never run. */
    public function secondsSinceLastTick(): ?int
    {
        return $this->last_tick_at?->diffInSeconds(now()) !== null
            ? (int) $this->last_tick_at->diffInSeconds(now())
            : null;
    }

    /** Is the cron considered down, given the configured staleness threshold? */
    public function isStale(int $staleAfterSeconds): bool
    {
        if ($this->last_tick_at === null) {
            return true;
        }

        return $this->last_tick_at->diffInSeconds(now()) > $staleAfterSeconds;
    }
}
