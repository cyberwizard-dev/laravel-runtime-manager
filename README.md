# Laravel Runtime Manager

Run your Laravel app's **scheduler, queues, maintenance, health checks and watchdog from a single cron entry** on ordinary shared hosting (cPanel, DirectAdmin, Hostinger, Namecheap, Bluehost…). No Supervisor, systemd, Redis, SSH or VPS required.

> Designed to pair with [`cyberwizard/laravel-ftp-deployer`](https://github.com/cyberwizard-dev/laravel-ftp-deployer): the deployer gets your code onto the host without SSH, and the runtime manager keeps it running there. The two are independent — integration is config-level only.

See [`DESIGN.md`](DESIGN.md) for the full architecture, rationale, and the phased plan.

> **Status:** Phases 0–7 implemented and tested (21 passing tests on Laravel 13 / PHP 8.5). One-cron tick with budget + locking + heartbeat; native scheduler and bounded database-queue processing; post-deploy pipeline; standalone-password dashboard + setup wizard with live verification; watchdog, health, housekeeping, cache-health, database-session GC; optional outbound HTTP-client monitoring; optional signed HTTP fallback trigger.

## How it works

One cron job, every minute, runs a self-contained `runner.php` that boots Laravel **in-process** (no `php artisan` subprocess, no `exec`) and executes a single bounded *tick*: acquire an overlap lock, run due scheduled tasks, process a budgeted batch of queued jobs, do housekeeping, record a heartbeat, and exit.

```cron
* * * * * /usr/local/bin/php /home/user/app/artisan-runtime.php >/dev/null 2>&1
```

Because a tick runs once a minute, queue latency floors at ~1 minute — excellent for most shared-hosting apps, but not a real-time/Supervisor replacement. The dashboard reports backlog honestly.

## Install

```bash
composer require cyberwizard/laravel-runtime-manager
php artisan runtime:install
```

`runtime:install` publishes the config and `runner.php`, runs the migrations, sets a standalone dashboard password, and prints the exact cron line. Then paste that line into cPanel → Cron Jobs.

Re-print the cron line anytime:

```bash
php artisan runtime:cron-command
```

## Dashboard

Visit `https://your-app/runtime` and sign in with the standalone password. Pages:

- **Overview** — cron liveness, pending/in-flight/failed jobs, scheduled-task count, recent ticks, health checks, deployment status.
- **Queue** — backlog by queue, failed jobs with retry/delete/flush (confirmed + audited), processing history.
- **Scheduler** — registered tasks with next-run and timezone; warnings when `onOneServer`/`runInBackground` would be no-ops on this host.
- **HTTP** — optional outbound HTTP-client monitoring (host, path, status, duration; never query strings, headers or bodies). Enable with `RUNTIME_HTTP_MONITOR=true`.
- **Logs** — tick history filterable by status/trigger/date.
- **Setup** — environment detection, the cron command, and a **Verify** button that runs live checks including a real queue round-trip.

## Integrating with the FTP deployer

Add the post-deploy verification to your `deploy.json` `post_extraction_commands` (after the deployer's own cache/migrate steps). It does **not** duplicate them — it verifies the app and marks it operational:

```json
"post_extraction_commands": [
    "config:cache",
    "route:cache",
    "migrate --force",
    "up",
    "runtime:post-deploy"
]
```

`runtime:post-deploy` only runs migrations if you pass `--migrate` — safe to run on every deploy.

## Commands

| Command | Purpose |
|---|---|
| `runtime:install` | Publish config + runner, migrate, set dashboard password, print cron line |
| `runtime:cron-command` | Print the exact single cron command for this host |
| `runtime:tick` | Run one tick by hand (what the cron runs) |
| `runtime:post-deploy` | Verify + mark operational after a deploy (`--migrate` to migrate) |
| `runtime:http-url` | Print the signed HTTP fallback trigger URL + pinger snippet |

## No cron? Optional signed HTTP trigger

For hosts without cron, enable `RUNTIME_HTTP_TRIGGER_ENABLED=true` with a `RUNTIME_HTTP_TRIGGER_SECRET`, then have an external uptime service hit the signed URL every minute (`php artisan runtime:http-url` prints it and an example). The endpoint is HMAC-signed, timestamp-windowed, replay-protected and rate-limited; it is **off by default**.

## Requirements

- PHP 8.1+
- Laravel 11, 12 or 13
- A database connection (the recommended queue driver and the tick lock use it)

## Security

- `runner.php` lives outside the web root and refuses to run under a web SAPI.
- The dashboard is behind a standalone password (hashed, in `.env`), independent of your app's auth.
- An optional HTTP fallback trigger (for hosts without cron) is **off by default** and, when enabled, is HMAC-signed, timestamp-windowed, replay-protected and rate-limited.

## License

MIT.
