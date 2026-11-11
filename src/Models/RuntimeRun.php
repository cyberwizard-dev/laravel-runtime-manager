<?php

namespace Cyberwizard\RuntimeManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per tick, with per-service outcomes and timings. Powers the run
 * history and average-duration metrics in the dashboard.
 *
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string $status  success|partial|failed|skipped
 * @property string $trigger cli|http
 * @property array|null $services
 * @property string|null $error
 */
class RuntimeRun extends Model
{
    protected $table = 'runtime_runs';

    protected $guarded = [];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'services'    => 'array',
    ];

    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';
}
