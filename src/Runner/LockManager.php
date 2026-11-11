<?php

namespace Cyberwizard\RuntimeManager\Runner;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Throwable;

/**
 * Tick lock preventing overlapping cron executions.
 *
 * Primary: Laravel's atomic cache lock on a lock-capable store (database
 * recommended). The lock carries an owner token and a TTL, so a tick that is
 * killed mid-run self-recovers when the TTL expires — no manual stale-lock
 * cleanup required.
 *
 * Fallback: flock() on a file under storage, for hosts without a lock-capable
 * cache store. (flock can be unreliable on some NFS shared filesystems; the
 * setup wizard flags those and recommends the database store.)
 *
 * Phase 0 ships a working acquire/release; Phase 1 layers heartbeat-aware
 * lock extension on top.
 */
class LockManager
{
    private ?Lock $cacheLock = null;

    /** @var resource|null */
    private $flockHandle = null;

    public function __construct(
        private CacheFactory $cache,
        private array $config,
    ) {
    }

    /** Attempt to acquire the tick lock. Returns false if another tick holds it. */
    public function acquire(): bool
    {
        if (($this->config['driver'] ?? 'cache') === 'flock') {
            return $this->acquireFlock();
        }

        try {
            $store = $this->config['cache_store'] ?? null;
            $this->cacheLock = $this->cache->store($store)->lock(
                $this->config['key'] ?? 'runtime-manager:tick',
                (int) ($this->config['ttl_seconds'] ?? 55),
            );

            return (bool) $this->cacheLock->get();
        } catch (Throwable $e) {
            // Store does not support atomic locks (e.g. file/array). Degrade to flock.
            $this->cacheLock = null;

            return $this->acquireFlock();
        }
    }

    public function release(): void
    {
        if ($this->cacheLock !== null) {
            try {
                $this->cacheLock->release();
            } catch (Throwable) {
                // Ignore; TTL will expire it.
            }
            $this->cacheLock = null;
        }

        if (is_resource($this->flockHandle)) {
            @flock($this->flockHandle, LOCK_UN);
            @fclose($this->flockHandle);
            $this->flockHandle = null;
        }
    }

    private function acquireFlock(): bool
    {
        $path = storage_path('app/runtime');
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        $file = $path . '/tick.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return false;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        $this->flockHandle = $handle;

        return true;
    }
}
