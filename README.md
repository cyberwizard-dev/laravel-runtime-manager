# Laravel Runtime Manager

**Run your Laravel app's scheduler, queue workers, maintenance, health checks and watchdog from a single cron entry — on ordinary shared hosting.** No Supervisor, systemd, Redis, SSH or VPS required.

[![Tests](https://img.shields.io/badge/tests-22%20passing-success)](#local-development--testing)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-ff2d20)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](#requirements)
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

- **PHP 8.2+**
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

### Adding it to an existing app

This is designed to drop into an **existing** Laravel 11/12/13 app with zero changes to your code — it's just a Composer package. Specifically:

- **Nothing you have is modified or replaced.** Your existing scheduled tasks (`routes/console.php`) and queued jobs keep working exactly as they are — the package *invokes* Laravel's native `schedule:run` and `queue:work`, it doesn't reimplement them.
- **The service provider is auto-discovered** (via `extra.laravel.providers`), so there's nothing to register manually.
- **Migrations are additive only.** It creates its own `runtime_state`, `runtime_runs`, `runtime_audit` and (optional) `runtime_http_requests` tables. It never alters your tables. It reuses Laravel's native `jobs` / `failed_jobs` / `cache` / `sessions` tables rather than creating its own.
- **Routes are namespaced** under a configurable prefix (default `/runtime`) and guarded by their own password, so they won't collide with or expose your app.
- **Reversible.** To remove it: delete the cron job, `composer remove cyberwizard/laravel-runtime-manager`, drop the `runtime_*` tables, and delete `artisan-runtime.php` and `config/runtime-manager.php`.

So on an app that's already live: `composer require` → `php artisan migrate` → `php artisan runtime:install` → add the one cron job. If that app currently runs the scheduler via its own `* * * * * php artisan schedule:run` cron, you can keep it or replace it with this one runner (which already calls `schedule:run` for you).

## Deploying on shared hosting (cPanel)

This is the heart of the tool, so here is the **complete start-to-finish process**. The whole thing is normally a 10–15 minute, one-time setup.

### The one catch: running `composer` and `artisan` with no SSH

Shared hosting usually has no SSH, so you can't run `composer require` or `php artisan` directly on the server. You get around this by doing the Composer/Artisan work **on your own computer** and shipping the result up. There are three ways to trigger the one-time install on the server; pick whichever your host supports:

- **(easiest) cPanel Terminal** — some hosts have **cPanel → Terminal**. If yours does, you have CLI: skip to the [with-CLI quick path](#quick-path-if-you-have-ssh-or-cpanel-terminal).
- **A one-time cron** — add a temporary cron that runs the install command once, then switch it to the normal runner (shown below).
- **The FTP deployer** — [`laravel-ftp-deployer`](https://github.com/cyberwizard-dev/laravel-ftp-deployer) runs Artisan on the server over an HTTP bridge, and its deploy already runs `migrate --force`.

---

### Full walkthrough (pure FTP, no SSH)

#### Step 1 — Add the package on your computer

In your Laravel project locally:

```bash
composer require cyberwizard/laravel-runtime-manager
php artisan runtime:install --password="choose-a-dashboard-password"
```

`runtime:install` does four things you'll reuse:
- creates **`artisan-runtime.php`** in your project root (the cron's entry point),
- publishes `config/runtime-manager.php`,
- runs migrations **locally** (harmless — just so you can see it works),
- writes **`RUNTIME_DASHBOARD_PASSWORD='$2y$...'`** (a bcrypt hash) into your local `.env`. **Copy that whole line** — you'll paste it into the server's `.env`.

#### Step 2 — Create the MySQL database in cPanel

**cPanel → MySQL® Databases**: create a database, create a user, then **add the user to the database with ALL PRIVILEGES**. Note the database name, username and password (cPanel usually prefixes them with your account name).

#### Step 3 — Prepare your production `.env`

Use `.env.prod` (if deploying with the FTP deployer) or edit `.env` on the server afterwards. Set:

```dotenv
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=youracct_appdb
DB_USERNAME=youracct_appuser
DB_PASSWORD=your-db-password

# Database-backed services — the sweet spot on shared hosting:
QUEUE_CONNECTION=database
CACHE_STORE=database          # also enables onOneServer() + the atomic tick lock
SESSION_DRIVER=database

# Paste the exact line runtime:install wrote locally in Step 1:
RUNTIME_DASHBOARD_PASSWORD='$2y$12$........................................'
```

#### Step 4 — Upload the app (including `vendor/` and `artisan-runtime.php`)

Shared hosting can't run `composer install`, so you upload the fully-built app — `vendor/` included.

- **With the FTP deployer:** `vendor/bin/ftp-deploy --first-time` (its `--first-time` mode deliberately includes `vendor/`). Its post-extraction step runs `migrate --force`, which creates the `jobs`, `failed_jobs` **and** the `runtime_*` tables for you.
- **Or manually:** zip the project, upload via **cPanel → File Manager**, and extract.

**Where files go:** your app root (the folder with `artisan`, `vendor/`, `bootstrap/`) normally sits **one level above `public_html`**, and only `public/` is the web root. `artisan-runtime.php` must land in that **app root**, so it is *not* reachable from the web. If your host serves the whole account from `public_html`, keep the app in a subfolder and point the domain's document root at its `public/`.

#### Step 5 — Run the migrations on the server (if your upload didn't)

The FTP deployer already did this via `migrate --force`. If you uploaded manually, run the migrations once using any method your host supports:
- cPanel Terminal: `php artisan migrate --force`
- or a one-time cron (see Step 6's pattern, but with `artisan migrate --force`).

#### Step 6 — Add the single cron job

**cPanel → Cron Jobs → Add New Cron Job.** Set the interval to **every minute** — the five fields are `* * * * *` (Common Settings → "Once Per Minute"). For the command, you need the server's absolute PHP CLI path and the absolute path to `artisan-runtime.php`:

```cron
* * * * * /usr/local/bin/php /home/youracct/yourapp/artisan-runtime.php >/dev/null 2>&1
```

To get the exact line for *your* server, run `php artisan runtime:cron-command` on the server (cPanel Terminal, or temporarily as a cron). It auto-detects the PHP CLI binary (e.g. `/usr/local/bin/php`, `/opt/cpanel/ea-php82/root/usr/bin/php`, CloudLinux `/opt/alt/php82/usr/bin/php`) and the runner path. If you can't run it on the server, build the command as `<server-PHP-CLI> <app-root>/artisan-runtime.php` — the app root is the deployer's `FTP_ROOT`. If the cron errors, your host's support can tell you the correct CLI binary path.

> **No install access at all?** Instead of Step 1's server install, add a **temporary** cron `* * * * * /usr/local/bin/php /home/youracct/yourapp/artisan runtime:install --migrate --password="your-pass" --force`, let it fire once, confirm the dashboard works, then **change that cron** to the `artisan-runtime.php` line above.

#### Step 7 — Verify

Open **`https://yourdomain.com/runtime`**, sign in with your dashboard password, and go to **Setup → Run verification**. It runs live checks — database, cache lock, storage, scheduler, and an actual dispatch-and-process **queue round-trip** — and the **Overview** page turns green within a minute or two as the cron starts ticking.

**Done.** Your scheduler and queues are now running from that one cron job.

---

### Quick path (if you have SSH or cPanel Terminal)

```bash
composer require cyberwizard/laravel-runtime-manager
php artisan runtime:install            # migrates, sets password, prints the cron line
php artisan runtime:cron-command       # copy this into cPanel → Cron Jobs (every minute)
```

Then open `/runtime` and verify.

---

### Using it day to day

Once it's running, you work with Laravel exactly as normal — the cron does the rest:

- **Queue a job:** `SomeJob::dispatch()` anywhere in your app. It runs within ~1 minute. Watch backlog/throughput on the **Queue** page.
- **Schedule a task:** add it to `routes/console.php` the usual way (`Schedule::command(...)->daily()`). It appears on the **Scheduler** page and runs automatically — no extra cron.
- **Deploy an update:** re-run the FTP deployer. Add `runtime:post-deploy` to your `deploy.json` `post_extraction_commands` so each deploy verifies health, fixes the storage link, and marks the app operational (see [Deployment integration](#deployment-integration)).
- **Handle failures:** failed jobs show on the **Queue** page with retry/delete/flush. Health problems show on **Overview**; set `RUNTIME_ALERT_EMAIL` and `RUNTIME_PING_URL` for proactive alerts.
- **Pause everything** without touching the cron: set `RUNTIME_ENABLED=false`.

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
RUNTIME_MAIL_MONITOR=false           # record outgoing mail (domains/subject only)
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
- **Mail** — optional outgoing-mail monitoring (recipient domains, count, subject, mailer; never full addresses or bodies), honestly framed as transport handoff, not inbox delivery.
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

## No cron? External URL trigger

Don't have (or don't want) a server cron? Drive the tick from any online cron/uptime service. One command sets it up:

```bash
php artisan runtime:http-url
```

It generates a token, enables the trigger, writes both to `.env`, and prints a ready URL:

```
https://your-app.com/runtime/trigger?token=<long-random-token>
```

Paste that into **cron-job.org, EasyCron, UptimeRobot, GitHub Actions cron** — anything that can request a URL on a schedule (GET or POST) — set to every minute. Reload cached config afterwards (`php artisan config:clear`). Rotate anytime with `--regenerate`.

**Two auth modes, either accepted:**

- **Token** (simple, above) — `?token=...` or the `X-Runtime-Token` header. Works with every cron-url service; prefer the header when supported, to keep the token out of access logs.
- **HMAC** (most secure, for pingers that can sign each request) — `X-Runtime-Timestamp` + `X-Runtime-Signature: hmac_sha256(secret, timestamp)`, timestamp-windowed and replay-protected:
  ```dotenv
  RUNTIME_HTTP_TRIGGER_ENABLED=true
  RUNTIME_HTTP_TRIGGER_SECRET=a-long-random-secret
  ```

The endpoint is **off by default**, rate-limited, and only runs a tick (returns `{status, duration_ms}` — no data exposed). The CLI runner stays SAPI-guarded regardless.

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

Run the package test suite (22 tests across the tick, lock, budget, real queue round-trip, dashboard auth, signed trigger, HTTP monitoring and session GC):

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
