# Laravel Runtime Manager

**Run your Laravel app's scheduler, queue workers, maintenance, health checks and watchdog from a single cron entry — on ordinary shared hosting.** No Supervisor, systemd, Redis, SSH or VPS required.

[![Tests](https://img.shields.io/badge/tests-21%20passing-success)](#local-development--testing)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-ff2d20)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](#requirements)
[![License](https://img.shields.io/badge/license-MIT-blue)](#license)

> Pairs with [`cyberwizard/laravel-ftp-deployer`](https://github.com/cyberwizard-dev/laravel-ftp-deployer): the deployer gets your code onto the host without SSH; the runtime manager keeps it running there. The two are independent — integration is config-level only.

---

## Table of contents

- [The problem](#the-problem)
- [The solution](#the-solution)
- [Why use it — advantages](#why-use-it--advantages)
- [Requirements](#requirements)
- [Installation](#installation)
- [Deploying on shared hosting (cPanel)](#deploying-on-shared-hosting-cpanel)
- [Configuration](#configuration)
- [The dashboard](#the-dashboard)
- [Scheduler](#scheduler)
- [Queues](#queues)
- [Deployment integration](#deployment-integration)
- [No cron? Signed HTTP fallback](#no-cron-signed-http-fallback)
- [Watchdog, health & alerts](#watchdog-health--alerts)
- [Security](#security)
- [Command reference](#command-reference)
- [How it works](#how-it-works)
- [Honest limitations](#honest-limitations)
- [Local development & testing](#local-development--testing)
- [License](#license)

---

## The problem

Laravel in production expects things shared hosting doesn't give you:

- a **scheduler** cron (`* * * * * php artisan schedule:run`),
- a **persistent queue worker** kept alive by Supervisor or systemd,
- often **Redis**, and almost always **SSH** to configure all of the above.

Most cPanel / DirectAdmin / Hostinger / Namecheap / Bluehost plans offer **FTP and a cron UI, and nothing else**. So queues and scheduled tasks either don't run, or you bolt on fragile hacks. That's the gap this package closes.

## The solution

You configure **one** cron job. That's the entire operations setup:

```cron
* * * * * /usr/local/bin/php /home/user/app/artisan-runtime.php >/dev/null 2>&1
```

Every minute that runner boots the Laravel kernel **once, in-process**, and executes a single bounded **tick**:

```
acquire overlap lock → run due scheduled tasks → process a budgeted batch of
queued jobs → housekeeping + health/watchdog → record heartbeat → exit
```

No `php artisan` subprocesses are spawned and no `exec()`/`shell_exec()` is required — the same technique the FTP deployer already proves works on hosts with no SSH. Queue latency floors at ~1 minute, which is excellent for the vast majority of shared-hosting apps.

## Why use it — advantages

| | Traditional setup | Laravel Runtime Manager |
|---|---|---|
| **Cron entries** | 1 for scheduler + manual worker setup | **1 total** |
| **Queue workers** | Supervisor / systemd (needs SSH + root) | Bounded `queue:work` driven by the cron, **no daemon** |
| **SSH required** | Yes | **No** |
| **Redis required** | Often | **No** — database driver |
| **Overlap protection** | Hope `withoutOverlapping` is set | Atomic tick lock, self-expiring |
| **Visibility** | Tail log files over FTP | **Web dashboard** — backlog, failures, ticks, health |
| **"Did my cron stop?"** | You find out from users | Dashboard staleness + optional dead-man's-switch ping |
| **Post-deploy** | Manual artisan over a hack | `runtime:post-deploy` verifies & marks operational |

Other things that matter on cheap hosting:

- **Lightweight.** One short-lived process per minute. No long-running PHP, no idle memory, bounded by a configurable execution budget that stays under provider CPU-second caps.
- **Native, not reinvented.** Wraps Laravel's own `schedule:run` and `queue:work`. Your existing scheduled tasks and jobs work unchanged. `withoutOverlapping`, timezones, retries, backoff and the database driver's double-processing protection are all preserved.
- **Honest.** The dashboard reports real backlog and real health; it never implies real-time processing it can't deliver, and it tells you when a feature (`onOneServer`, `runInBackground`) would silently degrade on your host.
- **Reusable.** A standard Composer package you drop into any Laravel 11/12/13 app.

## Requirements

- **PHP 8.1+**
- **Laravel 11, 12 or 13**
- **A database connection** (the recommended queue driver and the tick lock use it)
- A host that can run **one cron job** calling the PHP CLI (almost all shared hosts can; if yours can't, see [the HTTP fallback](#no-cron-signed-http-fallback))

## Installation

```bash
composer require cyberwizard/laravel-runtime-manager
php artisan runtime:install
```

`runtime:install` will:

1. publish `config/runtime-manager.php`,
2. publish `artisan-runtime.php` into your app root (outside the web root),
3. run the package migrations,
4. set a **standalone dashboard password** (printed once — store it),
5. print the exact cron line for your host.

Re-print the cron line anytime with `php artisan runtime:cron-command`.

## Deploying on shared hosting (cPanel)

A complete first deploy, assuming FTP-only access:

**1. Prepare locally.** Set your production queue/cache/session to the database driver in `.env` (recommended on shared hosting):

```dotenv
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Make sure the queue/cache/session tables exist (`php artisan queue:table`, `cache:table`, `session:table` if needed, then `migrate`).

**2. Upload.** Push your app to the host. The easiest no-SSH path is the companion [FTP deployer](https://github.com/cyberwizard-dev/laravel-ftp-deployer); or upload a ZIP via cPanel File Manager and extract. Ensure `artisan-runtime.php` lands in your **application root** (the folder containing `vendor/`, `bootstrap/`, `artisan`) — which is typically **one level above `public_html`**, so it is not web-accessible.

**3. Run the installer once.** If you have no CLI access at all, run it through the FTP deployer's remote-artisan bridge; otherwise via your host's "Terminal" or a one-off cron. This sets the dashboard password and confirms the runner.

**4. Add the single cron job.** In **cPanel → Cron Jobs**, add a job that runs **every minute** (`* * * * *`) with the command `runtime:cron-command` printed for you, e.g.:

```cron
* * * * * /usr/local/bin/php /home/youruser/yourapp/artisan-runtime.php >/dev/null 2>&1
```

> **Finding your PHP CLI path:** `runtime:cron-command` auto-detects it (common cPanel paths like `/usr/local/bin/php` or `/opt/cpanel/ea-phpXX/root/usr/bin/php`). If the cron fails, ask your host for the correct CLI binary and substitute it.

**5. Verify.** Open `https://yourdomain.com/runtime`, sign in, go to **Setup → Run verification**. It runs live checks — including dispatching and processing a real test job — and the **Overview** page turns green once the cron has ticked.

That's it. Your queues and scheduler are now running.

## Configuration

Everything is in `config/runtime-manager.php`, driven by `.env`. Highlights:

```dotenv
RUNTIME_ENABLED=true                 # master switch (pause without touching cron)
RUNTIME_BUDGET_SECONDS=50            # max wall-clock per tick (keep < 60 and < CPU caps)

RUNTIME_QUEUE_ENABLED=true
RUNTIME_QUEUE_CONNECTION=database

RUNTIME_DASHBOARD_PATH=runtime       # dashboard URL path
RUNTIME_DASHBOARD_PASSWORD=...       # bcrypt hash, set by the installer
RUNTIME_DASHBOARD_IPS=               # optional comma-separated IP allowlist

RUNTIME_STALE_AFTER=180              # seconds before cron is flagged "down"
RUNTIME_PING_URL=                    # optional external dead-man's-switch ping
RUNTIME_ALERT_EMAIL=                 # watchdog alert recipient

RUNTIME_HTTP_MONITOR=false           # record outbound HTTP-client calls
RUNTIME_HTTP_TRIGGER_ENABLED=false   # signed HTTP fallback (no-cron hosts)
RUNTIME_HTTP_TRIGGER_SECRET=
```

The execution budget is split across services (scheduler / queue / maintenance) by weight, each with a floor so none is starved. Queues are processed in priority order with per-queue budgets.

## The dashboard

Visit `https://yourdomain.com/runtime` and sign in with the standalone password.

- **Overview** — cron liveness, pending / in-flight / failed jobs, scheduled-task count, recent ticks, health checks, deployment status.
- **Queue** — backlog by queue, failed jobs with **retry / delete / flush** (confirmed and audited), processing history and average durations.
- **Scheduler** — registered tasks with next-run and timezone; warnings when `onOneServer` / `runInBackground` would be no-ops on this host.
- **HTTP** — optional outbound HTTP-client monitoring (host, path, status, duration; never query strings, headers or bodies).
- **Logs** — tick history filterable by status / trigger / date.
- **Setup** — environment detection, the cron command, and a **Verify** button that runs live checks.

Every metric and button is wired to real runtime state — nothing is simulated.

## Scheduler

Define tasks the normal Laravel way (in `routes/console.php` or your console kernel). You do **not** change anything — the runtime manager invokes `schedule:run` in-process each tick:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:build')->dailyAt('02:00');
Schedule::call(fn () => Cache::forget('stale'))->hourly()->withoutOverlapping();
```

`withoutOverlapping`, timezones and task output work natively. `onOneServer` requires an atomic-lock-capable cache store (use the `database` store); `runInBackground` requires process functions — both are detected and surfaced on the Scheduler page rather than silently dropped.

## Queues

Use Laravel queues normally. The **database driver is recommended** — its atomic job reservation prevents a job being processed twice even if two ticks overlap.

```php
ProcessPodcast::dispatch($podcast)->onQueue('high');
```

Each tick runs bounded `queue:work` cycles per queue, in priority order, within the queue's budget slice:

```php
// config/runtime-manager.php
'queue' => [
    'queues'   => ['high', 'default', 'low'],   // priority order
    'defaults' => ['max_jobs' => 50, 'max_time' => 20, 'tries' => 3, 'backoff' => 10, 'memory' => 96],
    'overrides'=> ['high' => ['max_jobs' => 100, 'max_time' => 25]],
],
```

Failed jobs land in Laravel's native `failed_jobs` table and are managed from the dashboard (retry / delete / flush, all audited).

## Deployment integration

Add the verification step to your `deploy.json` `post_extraction_commands` (after the deployer's own cache/migrate steps). It does **not** duplicate them — it verifies the app and marks it operational:

```json
"post_extraction_commands": [
    "config:cache",
    "route:cache",
    "migrate --force",
    "up",
    "runtime:post-deploy"
]
```

`runtime:post-deploy` checks health, ensures the `storage` symlink, verifies the scheduler and queue, and records the deployment. It runs migrations **only** if you pass `--migrate`, so it's safe to run on every deploy.

## No cron? Signed HTTP fallback

For the rare host without cron, enable an authenticated HTTP trigger and have an external uptime service (e.g. a cron-ping provider) hit it every minute:

```dotenv
RUNTIME_HTTP_TRIGGER_ENABLED=true
RUNTIME_HTTP_TRIGGER_SECRET=a-long-random-secret
```

```bash
php artisan runtime:http-url   # prints the URL + a ready pinger snippet
```

The endpoint is **off by default** and, when enabled, is HMAC-signed, timestamp-windowed, replay-protected (one-time nonce) and rate-limited. The CLI runner remains SAPI-guarded regardless.

## Watchdog, health & alerts

The watchdog runs each tick and turns failing health checks (DB down, cache failure, storage not writable, failed-job spikes, repeated tick failures, low disk, cron staleness) into alerts via your app's configured mail transport. If mail is misconfigured, the **transport failure is recorded and shown on the dashboard** — it never claims an alert was delivered when the SMTP handoff failed.

Because a dead cron can't report on itself, set `RUNTIME_PING_URL` to an external dead-man's-switch (e.g. healthchecks.io) — the runner pings it on each successful tick, and the external service alerts you if the pings stop.

## Security

- `artisan-runtime.php` lives outside the web root and **refuses to run under a web SAPI**.
- The dashboard uses a **standalone password** (hashed in `.env`), independent of your app's auth, with login throttling and an optional IP allowlist.
- Privileged dashboard actions (retry / flush / migrate) are **audited** to a dedicated table.
- The HTTP fallback is HMAC-signed, timestamp-windowed, replay-protected and rate-limited.
- Outbound HTTP monitoring never stores query strings, headers, credentials or bodies.

## Command reference

| Command | Purpose |
|---|---|
| `runtime:install` | Publish config + runner, migrate, set dashboard password, print cron line |
| `runtime:cron-command` | Print the exact single cron command for this host |
| `runtime:tick` | Run one tick by hand (what the cron runs) |
| `runtime:post-deploy` | Verify + mark operational after a deploy (`--migrate` to migrate) |
| `runtime:http-url` | Print the signed HTTP fallback trigger URL + pinger snippet |

## How it works

The runner boots the kernel once per tick and drives everything in-process:

```php
require __DIR__.'/vendor/autoload.php';
$app    = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->call('runtime:tick');   // scheduler + queue + maintenance, budgeted
```

A `Tick` orchestrator acquires an atomic cache lock (flock fallback), allocates the execution budget across services, runs each within its slice, records a per-tick row with per-service outcomes, updates the heartbeat, and releases the lock. See [`DESIGN.md`](DESIGN.md) for the full architecture, rationale and phased plan.

## Honest limitations

- **~1-minute floor.** A once-a-minute tick means queue latency is at least ~1 minute. Great for most apps; not a real-time or Supervisor replacement.
- **Throughput is bounded** by what one budgeted tick can finish — scale the budget and `max_jobs`, but a very high-volume queue still wants a real worker host.
- **`onOneServer` / `runInBackground`** degrade on file-cache / no-exec hosts (detected and shown, never silent).
- **Cron-down detection** from inside a dead cron is impossible; use the dashboard staleness view and the external ping.

## Local development & testing

Run the package test suite (21 tests across the tick, lock, budget, real queue round-trip, dashboard auth, signed trigger, HTTP monitoring and session GC):

```bash
composer install
vendor/bin/phpunit
```

Try it in a throwaway app:

```bash
composer create-project laravel/laravel rtm-demo && cd rtm-demo
composer config repositories.rtm path ../laravel-runtime-manager
composer require "cyberwizard/laravel-runtime-manager:@dev"
php artisan migrate
php artisan runtime:install --migrate --password=secret
php artisan tinker --execute="App\Jobs\SomeJob::dispatch();"   # queue a job
php artisan runtime:tick                                        # process it
php artisan serve                                               # open /runtime

# simulate the cron in another terminal:
while true; do php artisan-runtime.php; sleep 20; done
```

## License

The MIT License (MIT).
