<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false, the cron tick boots, records a heartbeat, and exits without
    | running any services. Useful for pausing the runtime without touching
    | the cron entry.
    */
    'enabled' => env('RUNTIME_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Execution budget (seconds)
    |--------------------------------------------------------------------------
    | Total wall-clock time one tick may spend, kept comfortably under the
    | 60s cron window and under typical shared-host CPU-second caps. Services
    | are allocated slices of this budget by weight, each with a floor so no
    | service is starved. Phase 1 consumes these; Phase 0 only enforces the
    | overall deadline.
    */
    'budget' => [
        'total_seconds' => env('RUNTIME_BUDGET_SECONDS', 50),
        'weights' => [
            'scheduler'   => 1,
            'queue'       => 4,
            'maintenance' => 1,
        ],
        // Minimum seconds guaranteed to each service when it has work.
        'floors' => [
            'scheduler'   => 5,
            'queue'       => 10,
            'maintenance' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tick lock
    |--------------------------------------------------------------------------
    | Prevents overlapping ticks. Primary strategy is Laravel's atomic cache
    | lock on a lock-capable store (database recommended). 'flock' is a
    | detected fallback only. TTL self-expires so a killed tick recovers.
    */
    'lock' => [
        'driver'      => env('RUNTIME_LOCK_DRIVER', 'cache'), // cache | flock
        'cache_store' => env('RUNTIME_LOCK_CACHE_STORE', 'database'),
        'key'         => 'runtime-manager:tick',
        'ttl_seconds' => env('RUNTIME_LOCK_TTL', 55),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler service
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'enabled' => env('RUNTIME_SCHEDULER_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue service
    |--------------------------------------------------------------------------
    | Bounded, short-lived `queue:work` invocations driven in-process each
    | tick. 'queues' are processed in priority order (first = highest). Each
    | entry may override max_jobs / max_time / tries / backoff / memory.
    */
    'queue' => [
        'enabled'     => env('RUNTIME_QUEUE_ENABLED', true),
        'connection'  => env('RUNTIME_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queues'      => ['high', 'default', 'low'],
        'defaults'    => [
            'max_jobs' => 50,
            'max_time' => 20,   // seconds, hard cap per bounded worker run
            'tries'    => 3,
            'backoff'  => 10,   // seconds
            'memory'   => 96,   // MB
            'sleep'    => 0,
        ],
        'overrides'   => [
            // 'high' => ['max_jobs' => 100, 'max_time' => 25],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard & setup wizard (standalone password auth)
    |--------------------------------------------------------------------------
    | Independent of the application's own authentication. The password is
    | stored hashed in the environment; `runtime:install` sets it. Login is
    | throttled and may be restricted to an IP allowlist.
    */
    'dashboard' => [
        'enabled'        => env('RUNTIME_DASHBOARD_ENABLED', true),
        'path'           => env('RUNTIME_DASHBOARD_PATH', 'runtime'),
        'middleware'     => ['web'],
        'password_hash'  => env('RUNTIME_DASHBOARD_PASSWORD'), // bcrypt/argon hash, set by installer
        'session_ttl'    => env('RUNTIME_DASHBOARD_SESSION_TTL', 120), // minutes
        'ip_allowlist'   => array_filter(explode(',', (string) env('RUNTIME_DASHBOARD_IPS', ''))),
        'login_throttle' => '5,1', // attempts, minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP fallback trigger (optional, OFF by default)
    |--------------------------------------------------------------------------
    | For hosts without cron: an external uptime pinger hits a signed route
    | each minute. Guarded by HMAC(secret, timestamp) + timestamp window +
    | replay nonce + rate limit. The CLI runner remains SAPI-guarded.
    */
    'http_trigger' => [
        'enabled'        => env('RUNTIME_HTTP_TRIGGER_ENABLED', false),
        'secret'         => env('RUNTIME_HTTP_TRIGGER_SECRET'),
        'window_seconds' => 60,
        'throttle'       => '2,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance (housekeeping + cache health + watchdog)
    |--------------------------------------------------------------------------
    */
    'maintenance' => [
        'enabled' => env('RUNTIME_MAINTENANCE_ENABLED', true),
    ],

    'housekeeping' => [
        'log_max_bytes'    => env('RUNTIME_LOG_MAX_BYTES', 20 * 1048576), // rotate laravel.log above this
        'gc_db_sessions'   => env('RUNTIME_GC_DB_SESSIONS', true),        // delete expired database sessions
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP monitoring (optional, OFF by default)
    |--------------------------------------------------------------------------
    | Records destination host, path, status, duration and failures for calls
    | made through Laravel's HTTP client. Never stores query strings, headers,
    | credentials or bodies.
    */
    'http_monitor' => [
        'enabled'        => env('RUNTIME_HTTP_MONITOR', false),
        'retention_hours'=> env('RUNTIME_HTTP_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing mail monitoring (optional, OFF by default)
    |--------------------------------------------------------------------------
    | Records transport handoffs for mail sent through Laravel. Stores recipient
    | domains, a count, the subject and the mailer — never full addresses or
    | bodies. A recorded "sent" means the transport accepted the message; it is
    | NOT a guarantee of inbox delivery.
    */
    'mail_monitor' => [
        'enabled'         => env('RUNTIME_MAIL_MONITOR', false),
        'retention_hours' => env('RUNTIME_MAIL_RETENTION_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watchdog / heartbeat
    |--------------------------------------------------------------------------
    | 'stale_after' marks the cron "down" in the dashboard when no tick has
    | been recorded for this many seconds. 'external_ping_url' is an optional
    | dead-man's-switch endpoint pinged on each successful tick so an outside
    | monitor can alert if the cron stops (the only reliable way to detect it).
    | 'alert_email' receives watchdog alerts via the app's mail transport;
    | transport failures are recorded, never reported as delivered.
    */
    'watchdog' => [
        'stale_after_seconds'    => env('RUNTIME_STALE_AFTER', 180),
        'external_ping_url'      => env('RUNTIME_PING_URL'),
        'alert_email'            => env('RUNTIME_ALERT_EMAIL'),
        'alert_cooldown_minutes' => env('RUNTIME_ALERT_COOLDOWN', 60),
        'failed_jobs_warn'       => env('RUNTIME_FAILED_JOBS_WARN', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Run history retention
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'runs_days'  => env('RUNTIME_RUNS_RETENTION_DAYS', 14),
        'audit_days' => env('RUNTIME_AUDIT_RETENTION_DAYS', 90),
    ],
];
