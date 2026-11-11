<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Support\EnvironmentProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class SetupController extends Controller
{
    public function index(EnvironmentProbe $probe): View
    {
        $detected = $probe->detect();

        $trigger = (array) config('runtime-manager.http_trigger');
        $triggerToken = (string) ($trigger['token'] ?? '');
        $triggerUrl = null;
        if (($trigger['enabled'] ?? false) && $triggerToken !== '') {
            $triggerUrl = rtrim((string) config('app.url'), '/')
                . '/' . trim((string) config('runtime-manager.dashboard.path', 'runtime'), '/')
                . '/trigger?token=' . $triggerToken;
        }

        return view('runtime-manager::setup', [
            'env'            => $detected,
            'ready'          => $probe->isReady($detected),
            'triggerEnabled' => (bool) ($trigger['enabled'] ?? false),
            'triggerHasToken'=> $triggerToken !== '',
            'triggerUrl'     => $triggerUrl,
        ]);
    }

    /** Runs real verification checks and returns their results as JSON. */
    public function verify(Kernel $kernel): JsonResponse
    {
        return response()->json([
            'checks' => [
                $this->bootstrapCheck(),
                $this->databaseCheck(),
                $this->cacheLockCheck(),
                $this->storageCheck(),
                $this->schedulerCheck($kernel),
                $this->queueRoundTripCheck($kernel),
                $this->cronLivenessCheck(),
            ],
        ]);
    }

    private function result(string $key, bool $ok, string $message): array
    {
        return ['key' => $key, 'status' => $ok ? 'ok' : 'fail', 'message' => $message];
    }

    private function bootstrapCheck(): array
    {
        return $this->result('bootstrap', true, 'Laravel kernel booted for this request.');
    }

    private function databaseCheck(): array
    {
        try {
            app('db')->connection()->select('select 1');

            return $this->result('database', true, 'Connection OK.');
        } catch (Throwable $e) {
            return $this->result('database', false, $e->getMessage());
        }
    }

    private function cacheLockCheck(): array
    {
        try {
            $store = config('runtime-manager.lock.cache_store');
            $lock = Cache::store($store)->lock('runtime-manager:verify-lock', 5);
            if (! $lock->get()) {
                return $this->result('lock', false, 'Could not acquire a test lock.');
            }
            $lock->release();

            return $this->result('lock', true, 'Atomic lock acquire/release OK.');
        } catch (Throwable $e) {
            return $this->result('lock', false, 'Lock store not atomic: ' . $e->getMessage() . ' (flock fallback will be used).');
        }
    }

    private function storageCheck(): array
    {
        $paths = [storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')];
        $bad = array_filter($paths, fn ($p) => ! is_writable($p));

        return $bad === []
            ? $this->result('storage', true, 'Required directories writable.')
            : $this->result('storage', false, 'Not writable: ' . implode(', ', $bad));
    }

    private function schedulerCheck(Kernel $kernel): array
    {
        try {
            $code = $kernel->call('schedule:run');

            return $this->result('scheduler', $code === 0, $code === 0 ? 'schedule:run executed.' : "Exit code {$code}.");
        } catch (Throwable $e) {
            return $this->result('scheduler', false, $e->getMessage());
        }
    }

    /** Dispatches a real job and processes it through one bounded worker cycle. */
    private function queueRoundTripCheck(Kernel $kernel): array
    {
        $conn  = config('runtime-manager.queue.connection') ?: config('queue.default');
        $queue = 'runtime-verify';
        $token = Str::random(16);
        $key   = 'runtime-manager:verify-job:' . $token;

        try {
            Cache::forget($key);

            dispatch(function () use ($key) {
                Cache::put($key, 'done', 120);
            })->onConnection($conn)->onQueue($queue);

            $kernel->call('queue:work', [
                'connection'        => $conn,
                '--queue'           => $queue,
                '--once'            => true,
                '--stop-when-empty' => true,
                '--max-time'        => 10,
            ]);

            $ok = Cache::pull($key) === 'done';

            return $this->result('queue', $ok, $ok
                ? "Dispatched and processed a job on '{$conn}'."
                : 'Job did not complete — check the queue connection and jobs table.');
        } catch (Throwable $e) {
            return $this->result('queue', false, $e->getMessage());
        }
    }

    private function cronLivenessCheck(): array
    {
        $staleAfter = (int) config('runtime-manager.watchdog.stale_after_seconds', 180);

        try {
            $state = RuntimeState::query()->find(1);
        } catch (Throwable $e) {
            return $this->result('cron', false, $e->getMessage());
        }

        if (! $state || ! $state->last_tick_at) {
            return $this->result('cron', false, 'No tick recorded yet — confirm the cron job is configured.');
        }

        $age = (int) $state->last_tick_at->diffInSeconds(now());

        return $this->result('cron', $age <= $staleAfter, "Last tick {$age}s ago (threshold {$staleAfter}s).");
    }
}
