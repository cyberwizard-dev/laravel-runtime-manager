# Laravel Runtime Manager — Architecture & Implementation Design

**Status:** Approved and **implemented** — Phases 0–7 built and tested (19 passing tests, Laravel 13 / PHP 8.5). This document remains the architecture reference; see README for usage.
**Target:** Laravel 11 / 12 / 13 · PHP 8.1+ · cPanel / DirectAdmin / Hostinger / Namecheap / Bluehost and similar shared hosting.
**Working name / package:** `cyberwizard/laravel-runtime-manager` (TBD).
**Companion package:** `cyberwizard/laravel-ftp-deployer` (integrated via adapter, kept independent).

---

## 0. What this is — and what it honestly is not

A reusable Laravel package plus a lightweight, self-bootstrapping PHP runner that turns **one cron entry** into the execution engine for a Laravel app on shared hosting: scheduler, queues, maintenance, health, and watchdog — no Supervisor, systemd, Redis, SSH, or VPS.

**Honest framing (the mechanics bound the product, so the UI must not oversell it):**

- A once-per-minute cron tick means **queue latency floors at ~1 minute** and peak throughput is *(jobs finished within one budgeted tick) × (ticks per minute)*. This is excellent for most shared-hosting apps; it is **not** real-time and is **not** Supervisor. The dashboard will state backlog and expected drain time rather than imply instant processing.
- "Detecting that cron stopped" **cannot be done from inside a dead cron** alone. We provide in-dashboard staleness detection (visible when a human/pinger loads it) *and* an optional outbound dead-man's-switch ping to an external monitor. We will not claim self-healing that physics doesn't allow.
- Per spec §9: successful SMTP handoff ≠ inbox delivery. We report transport-level failures only, and say so.

---

## 1. Findings from analyzing `laravel-ftp-deployer` (v1.3.0)

Read from source, not the README. Key facts that shape this design:

| Capability | How the deployer does it | Consequence for us |
|---|---|---|
| **Remote execution without SSH/CLI** | Uploads ephemeral `artisan_run_<hex>.php` to a web-reachable dir; triggers via HTTP; the helper does `require '../bootstrap/app.php'` → `$app->make(Console\Kernel::class)` → runs Artisan **in-process**; deletes itself. | Our runner reuses this exact in-process bootstrap pattern — **one process per tick, no `php artisan` subprocesses.** |
| **Auth** | `bin2hex(random_bytes(32))` one-time token in `Authorization: Bearer …`; randomized filename; instant cleanup. | We mirror the token idea for the deploy path, but a *recurring* HTTP fallback needs a **stable HMAC secret + timestamp window + replay nonce**, not a one-time token. |
| **No-exec awareness** | Extractor checks `function_exists('shell_exec')`, falls back, logs "exec is disabled". | Confirms spec §10: never assume `exec`/`shell_exec`/`proc_open`. Our internal calls use in-process Artisan, not shelling out. |
| **Post-deploy ops already owned** | `post_extraction_commands` runs `config:cache`, `route:cache`, `migrate --force`, `db:seed --force`, `up`; helper fixes `storage/` + `bootstrap/cache` perms and purges stale bootstrap cache. | **We do NOT duplicate deploy-time caching/migration/permissions.** We add *operational* post-deploy steps (health verify, storage:link check, scheduler/queue verification, "mark operational") and let the developer insert our Artisan commands into `post_extraction_commands` / `custom_commands`. |
| **Config surface** | `.env.prod` → `.env` fallback; `deploy.json` with `exclude`, `pre_/post_` and `custom_commands` (allowlisted). | Integration is **config-level**: the deployer invokes our Artisan commands by name. No code coupling. |
| **Entry points** | `bin/ftp-deploy`, `bin/remote-artisan` (allowlist only), `bin/remote-tinker`, `bin/update-env`. | The adapter targets `custom_commands` + `post_extraction_commands`; optionally a thin "deployment finished" marker the runner detects. |

**Integration principle (spec §13):** the runtime core stays 100% independent of the deployer. They meet through (a) Artisan commands the deployer already knows how to run, and (b) an optional deployment marker/state row. No hard dependency in either direction.

---

## 2. Laravel internals research — how we invoke native components (no reinvention, spec §16)

### 2.1 Scheduler
- **Entry:** `schedule:run` evaluates due events for the current minute. We call it **in-process** once per tick: `Artisan::call('schedule:run')`. This preserves everything native.
- **Definition location:** Laravel 11+ defines schedules in `routes/console.php` via the `Schedule` facade and `bootstrap/app.php` `->withSchedule()`; older/console-Kernel apps still work. We read the app's own schedule — **developers never rewrite their tasks** (spec §4).
- **`withoutOverlapping`:** native, backed by the scheduling mutex in the **cache store**. Works with the `file` cache. ✅
- **`onOneServer`:** requires an **atomic-lock-capable cache store** (`database`, `redis`, `memcached`, `dynamodb`). The `file`/`array` stores **cannot** provide it. → We'll detect this and recommend the **database cache store**; degrade gracefully with a clear dashboard note where unavailable.
- **`runInBackground`:** dispatches via process/`exec`. On no-exec shared hosting this is unsupported → we detect and surface it as "runs inline instead" rather than silently dropping tasks.
- **Timezone / output:** `->timezone()` honored natively; `->sendOutputTo()` captured; `->emailOutputTo()` depends on working mail (diagnosed, not assumed).

### 2.2 Queues
- **Recommended driver:** `database`. Its reservation (`reserved_at` + atomic `SELECT … FOR UPDATE`) **already prevents double-processing across overlapping cron ticks** — a critical safety property we get for free.
- **Bounded worker primitive:** `queue:work --queue=high,default,low --stop-when-empty --max-jobs=N --max-time=S --memory=M --tries=T --backoff=…`. This is Laravel's own supported way to run a **short-lived, bounded** worker — exactly our cycle. We invoke it in-process per tick.
- **Priority / multiple queues:** native `--queue=a,b,c` ordering; **per-queue budgets** = separate bounded invocations each with its own `--max-time`, scheduled by our budget allocator to avoid starvation.
- **Failed jobs / retries / backoff:** native `failed_jobs` table, `queue:retry`, `queue:forget`, `queue:flush`, job `tries`/`backoff()`/`retryUntil()`. We surface and drive these; we don't replace them.
- **Abandoned-job recovery:** DB driver's `retry_after` returns stalled reservations to the queue automatically; we expose visibility and a manual "release reserved" action (audited).
- **Memory:** in-process `queue:work` memory growth is bounded by `--max-jobs`/`--max-time`/`--memory`. `queue:work` catches per-job **exceptions** (marks failed, continues). A hard **fatal/OOM** aborts the tick → DB reservation + `retry_after` + our stale-lock expiry recover on the next tick. Documented, not hidden.

### 2.3 Bootstrapping without a global CLI (the core trick, borrowed from the deployer)
```php
require __DIR__.'/../vendor/autoload.php';
$app    = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
// one boot per tick; all services call Artisan::call(...) in-process
```
The **only** CLI dependency is cron invoking `php runner.php` — which cPanel/DirectAdmin provide. All internal work is in-process, satisfying "don't spawn unlimited processes / don't bootstrap unnecessarily" (spec §12) and the no-exec constraint (spec §10).

---

## 3. Execution model & the one-cron engine

```
cPanel cron: * * * * * /usr/local/bin/php /home/user/app/artisan-runtime/runner.php >/dev/null 2>&1
                                   │
                                   ▼
          runner.php  (CLI-SAPI-guarded; refuses HTTP unless signed fallback is on)
                                   │ boot kernel once
                                   ▼
                     ┌──────────  Tick  ──────────┐
                     │ 1. acquire atomic lock      │  (skip tick if held → no overlap)
                     │ 2. write heartbeat/state    │
                     │ 3. health precheck          │  (DB reachable? storage writable?)
                     │ 4. Scheduler service        │  schedule:run  (budgeted)
                     │ 5. Queue service(s)         │  queue:work bounded, per-queue budget
                     │ 6. Maintenance/housekeeping │  cleanup, log rotation, stale locks
                     │ 7. update stats + heartbeat │
                     │ 8. optional external ping   │  dead-man's-switch
                     │ 9. release lock, exit        │
                     └─────────────────────────────┘
```

**Execution budget allocator (spec §2):** a total wall-clock budget (default e.g. 50s of a 60s window, configurable) is split across services by weight with **minimum guarantees** so queues never starve the scheduler and vice-versa. Each service checks the remaining budget before and during work and yields cooperatively. `queue:work` is additionally hard-capped by `--max-time`.

**Graceful shutdown:** a deadline timestamp + `pcntl_async_signals`/`register_shutdown_function` (where `pcntl` exists; time-check fallback where it doesn't) so a tick stops cleanly inside its budget and always releases the lock.

---

## 4. Locking, state & crash recovery (spec §12)

- **Primary lock:** Laravel's native **atomic cache lock** on the database store — `Cache::store('database')->lock('runtime:tick', $ttl)` — with owner token + TTL (self-expiring → **stale locks auto-recover**). Reuses Laravel-native infra (spec §16).
- **Fallback lock:** `flock()` on `storage/app/runtime/tick.lock` when no lock-capable store exists. (Noted risk: `flock` on some NFS shared filesystems is unreliable → DB lock preferred; wizard detects and recommends.)
- **Heartbeat / state:** `runtime_state` row updated each tick — `last_tick_at`, `last_tick_duration`, `current_phase`, `pid`, `php_sapi`, `last_error`. Atomic single-row update.
- **Crash recovery:** lock TTL expiry + heartbeat staleness detection + DB queue `retry_after` together recover from killed/interrupted ticks without manual intervention.
- **DB-outage tolerance (spec §12):** the tick wraps DB access in try/catch; on DB failure it skips DB-dependent services, writes a **file-based** error marker + still attempts the external heartbeat, and exits cleanly (partial failure, not a crash).

---

## 5. Package architecture & module layout

A **reusable package** + a **standalone runner file** published into the app. Core is modular so new runtime services drop in.

```
src/
  RuntimeManagerServiceProvider.php     # registers config, migrations, routes, commands, dashboard
  Runner/
    Tick.php                            # the lifecycle orchestrator (§3)
    ExecutionBudget.php                 # weighted budget + min guarantees
    Lock/ LockManager.php               #  CacheLock | FlockLock strategies
    Heartbeat.php                       # state writes + external dead-man ping
    Bootstrapper.php                    # in-process kernel boot helper
  Services/                             # each implements RuntimeService contract
    SchedulerService.php
    QueueService.php
    MaintenanceService.php
    WatchdogService.php
    HousekeepingService.php             # session/temp cleanup, log rotation
    CacheHealthService.php
    HttpMonitorService.php              # optional HTTP client middleware
    MailMonitorService.php              # transport-failure visibility only
  Contracts/ RuntimeService.php
  Deployment/
    DeploymentAdapter.php               # reads deployer state/marker; no hard dep
    PostDeployPipeline.php              # health, storage:link, verify, mark operational
  Support/ EnvironmentProbe.php         # wizard detections (§6)
  Http/
    Controllers/ (Setup, Dashboard, Queue, Scheduler, Logs)
    Middleware/ (DashboardGate, SignedRunnerTrigger, Throttle)
  Console/ Commands/
    runtime:install  runtime:verify  runtime:tick  runtime:post-deploy
    runtime:doctor   runtime:cron-command
stubs/ runner.php                        # published to app root (outside web root)
config/ runtime-manager.php
database/migrations/                     # runtime_state, runtime_runs, runtime_audit, http_monitor (opt)
routes/ runtime.php                      # setup + dashboard + optional signed trigger
resources/views/                         # wizard + dashboard (Blade, server-rendered, real data)
tests/                                   # unit + integration (shared-host simulation)
```

**Migrations we add:** `runtime_state` (singleton heartbeat), `runtime_runs` (per-tick + per-service history, durations, outcomes, errors), `runtime_audit` (privileged dashboard actions). We **reuse** native `jobs`/`failed_jobs`/`cache`/`cache_locks`/`sessions` rather than recreate them.

---

## 6. Setup wizard (spec §6) — every check is real

**EnvironmentProbe** detects: PHP web + **CLI version** (and mismatch), CLI binary path candidates (`/usr/local/bin/php`, `/opt/alt/phpXX/…`, `PHP_BINARY`), loaded extensions, **disabled functions** (`exec`/`shell_exec`/`proc_open`/`pcntl`), writable dirs, DB connectivity, available queue drivers, cache lock capability, cron support hints, document-root layout. Wizard steps: Environment → Runtime config → **Cron command generator** (exact copyable line + cPanel instructions) → Verify (bootstrap, scheduler dry-run, queue round-trip with a canary job, lock acquire/release, DB, writable, and a **real cron-execution confirmation** by watching `runtime_state.last_tick_at` advance).

## 7. Dashboard (spec §7) — no simulated data

Overview, Queue (backlog, processing, failed, retry/flush **with confirmation + audit**, history, avg duration), Scheduler (registered tasks, last/next, failures), Logs (cron/queue/scheduler/deploy/errors, filter by date/type/status). Every metric reads `runtime_state`/`runtime_runs`/native tables; every button calls a real, audited operation.

## 8. Watchdog & health (spec §8) + additional services (spec §9)

Watchdog monitors bootstrap failures, DB connectivity, queue/scheduler failures, failed-job spikes, storage perms, disk usage (where readable), **cron inactivity** (staleness of `last_tick_at`), PHP errors. Alerts via the app's configured mail transport **with diagnostics if mail is misconfigured** + optional external dead-man ping. Additional services: cache **health checks** (never blind flush of prod), optional outbound-HTTP monitoring via HTTP client middleware (status/duration/host only — **no** sensitive headers/bodies by default), session cleanup, log rotation, temp-file cleanup, Laravel maintenance commands.

---

## 9. Security model (spec §11) — concrete controls

- **runner.php is not publicly executable.** It is published **outside the web root** and aborts unless `PHP_SAPI === 'cli'`, *unless* the signed HTTP fallback is explicitly enabled.
- **HTTP fallback (optional, off by default):** a dedicated route guarded by **HMAC(secret, timestamp)** in a header, a ±N-second timestamp window, a **replay nonce** cached for the window, **rate limiting**, and `abort` on mismatch. (Stronger than a one-time token because the trigger recurs.) Secret in `.env`, never in DB, never in URL.
- **Dashboard & wizard auth (locked: standalone password).** Self-contained, app-auth-independent (works even for apps with no user model). A `RUNTIME_DASHBOARD_PASSWORD` in `.env` (stored **hashed**, verified with `hash_equals`/`Hash::check`), establishing a short-lived signed session cookie. Default-deny, CSRF via web middleware, login throttled against brute force, optional IP allowlist. The wizard is gated the same way; first-run install sets the password.
- **Credentials:** the runtime manager does not deploy, so it **stores no FTP/deploy credentials**. The only secrets it holds are the dashboard password hash and the optional HTTP-trigger secret — both in `.env`, never in the DB, never in a URL.
- **Operations:** all runtime operations strictly validated and allowlisted; destructive actions (migrate, flush failed) require explicit enablement + confirmation + **audit log**; path-traversal-safe file handling; env vars handled safely.

---

## 10. Technical risks & mitigations

| # | Risk | Mitigation |
|---|---|---|
| 1 | No cron, or CLI php path differs | Wizard detects path + cron; **signed HTTP fallback** via external pinger when cron absent |
| 2 | `onOneServer`/`runInBackground` unsupported (file cache / no-exec) | Detect + recommend DB cache store; surface degradation honestly; never silently drop tasks |
| 3 | In-process `queue:work` memory growth / fatal mid-tick | Bound by `--max-jobs/--max-time/--memory`; DB reservation + `retry_after` + lock TTL recover; per-job exceptions already handled by `queue:work` |
| 4 | Provider CPU-seconds / process caps | Conservative, **configurable** budget well under the window; single process per tick |
| 5 | Tick runs > interval → overlap | Atomic lock skips overlapping ticks (safe); dashboard warns if ticks are being skipped |
| 6 | `flock` unreliable on NFS | Prefer DB cache lock; wizard flags filesystem-lock hosts |
| 7 | DB outage | Try/catch, skip DB services, file error marker + external ping, clean exit |
| 8 | Stale code/opcache after deploy | Deployer already purges bootstrap cache; document deploy→clear→resume ordering |
| 9 | Cron-down invisibility | In-dashboard staleness + **external dead-man's-switch** ping (the only physically correct answer) |

---

## 11. Phased implementation plan (each phase independently testable; code only after approval)

- **Phase 0 — Skeleton.** Service provider, `config/runtime-manager.php`, migrations (`runtime_state/runs/audit`), `stubs/runner.php`, `runtime:install`, `runtime:cron-command`. *Done = installs into a fresh L11/12/13 app.*
- **Phase 1 — Runner core.** Kernel bootstrap, LockManager (cache + flock), ExecutionBudget, Heartbeat/state, graceful shutdown, `runtime_runs` logging, `runtime:tick`. *Tests: concurrent ticks (no overlap), stale-lock recovery, interrupted tick, budget enforcement, DB-outage tolerance.*
- **Phase 2 — Scheduler service.** In-process `schedule:run`, event recording, last/next, `withoutOverlapping`/timezone honored; capability detection for `onOneServer`/`runInBackground`. *Tests with real scheduled tasks.*
- **Phase 3 — Queue service.** Bounded `queue:work` per queue, priority, per-queue budgets, anti-starvation, failed/retry/backoff surfacing, abandoned-job visibility. *Tests: DB queue + real jobs, concurrent ticks (no double-processing), abandoned recovery.*
- **Phase 4 — Deployment integration.** `runtime:post-deploy` pipeline (health, `storage:link` check, scheduler/queue verify, mark operational — **migrations only if explicitly enabled**), DeploymentAdapter + marker, `deploy.json` snippet. *Tests for hooks + safety guards.*
- **Phase 5 — Wizard + dashboard.** EnvironmentProbe detections, cron generator, real Verify; server-rendered dashboard wired to real state. *Tests: probe matrix, verify flow, no-simulation assertions.*
- **Phase 6 — Watchdog + additional services + notifications.** Staleness detection, mail-transport diagnostics, external ping, cache health, HTTP monitor, session/temp/log housekeeping.
- **Phase 7 — Signed HTTP fallback.** HMAC + timestamp + replay nonce + throttle; off by default; security tests (replay, tamper, rate limit, unauth).
- **Cross-cutting.** Security hardening, docs (version-specific notes), CI matrix **L11/12/13 × PHP 8.1–8.4**, shared-host restriction simulation (disabled-functions, read-only dirs, no-DB).

---

## 12. Decisions & remaining defaults

**Locked (this review):**
1. **Database is assumed available.** DB queue + DB atomic cache lock are the primary, robust path. File-queue + `flock` remain a **detected fallback only**, not a first-class tested path — simpler and more reliable.
2. **Dashboard/wizard auth = standalone password** (`.env`, hashed), independent of the app's auth. See §9.

**Assumed defaults (will proceed on these unless corrected):**
3. **Version support: Laravel 11/12/13, PHP 8.1 floor** (matches the deployer). Laravel 10 not targeted.
4. **Package:** `cyberwizard/laravel-runtime-manager`, repo at `/Users/cyberwizard/laravel-runtime-manager/`.
```
