<?php

namespace Cyberwizard\RuntimeManager\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detects the shared-hosting environment for the setup wizard. Read-only and
 * side-effect free; the "verify" actions live in the SetupController.
 */
class EnvironmentProbe
{
    public function __construct(
        private Container $app,
        private ServerProbe $server,
    ) {
    }

    /** @return array<string,mixed> */
    public function detect(): array
    {
        return [
            'php'          => $this->php(),
            'extensions'   => $this->extensions(),
            'disabled'     => $this->server->disabledProcessFunctions(),
            'writable'     => $this->writable(),
            'database'     => $this->database(),
            'queue'        => $this->queue(),
            'cache_locks'  => $this->cacheLockCapable(),
            'runner'       => $this->runner(),
            'paths'        => $this->paths(),
        ];
    }

    private function php(): array
    {
        return [
            'web_version' => PHP_VERSION,
            'web_sapi'    => PHP_SAPI,
            'cli_binary'  => $this->server->phpCliBinary(),
            'note'        => 'CLI and web PHP versions can differ on shared hosting; the cron uses the CLI binary above.',
        ];
    }

    /** @return array<string,bool> */
    private function extensions(): array
    {
        $required = ['pdo', 'mbstring', 'openssl', 'json', 'ctype', 'tokenizer', 'fileinfo'];
        $out = [];
        foreach ($required as $ext) {
            $out[$ext] = extension_loaded($ext);
        }

        return $out;
    }

    /** @return array<string,bool> */
    private function writable(): array
    {
        return [
            'storage/framework' => is_writable(storage_path('framework')),
            'storage/logs'      => is_writable(storage_path('logs')),
            'bootstrap/cache'   => is_writable(base_path('bootstrap/cache')),
            'storage/app'       => is_writable(storage_path('app')),
        ];
    }

    private function database(): array
    {
        try {
            $this->app->make('db')->connection()->select('select 1');

            return ['connected' => true, 'driver' => (string) config('database.default')];
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    private function queue(): array
    {
        $default = (string) config('queue.default');
        $conn = config('runtime-manager.queue.connection') ?: $default;
        $table = (string) config("queue.connections.{$conn}.table", 'jobs');

        $hasTable = false;
        try {
            $hasTable = Schema::hasTable($table);
        } catch (Throwable) {
            // ignore
        }

        return [
            'default_connection' => $default,
            'runtime_connection' => $conn,
            'driver'             => (string) config("queue.connections.{$conn}.driver", 'unknown'),
            'jobs_table'         => $table,
            'jobs_table_exists'  => $hasTable,
            'recommended'        => 'database',
        ];
    }

    private function cacheLockCapable(): bool
    {
        try {
            return $this->app->make('cache')->store()->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider;
        } catch (Throwable) {
            return false;
        }
    }

    private function runner(): array
    {
        $path = $this->server->runnerPath(base_path());

        return [
            'path'      => $path,
            'published' => file_exists($path),
            'cron'      => $this->server->cronCommand(base_path()),
        ];
    }

    private function paths(): array
    {
        return [
            'base'   => base_path(),
            'public' => public_path(),
            'storage'=> storage_path(),
        ];
    }

    /** Overall readiness: true when nothing blocks the runtime from operating. */
    public function isReady(?array $detected = null): bool
    {
        $d = $detected ?? $this->detect();

        return ($d['database']['connected'] ?? false)
            && ! in_array(false, $d['extensions'], true)
            && ! in_array(false, $d['writable'], true)
            && ($d['runner']['published'] ?? false);
    }
}
