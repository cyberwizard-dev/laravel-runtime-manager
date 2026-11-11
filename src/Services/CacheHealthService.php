<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

/**
 * Non-destructive cache health probe: writes, reads back, and removes a single
 * canary key. Never flushes the cache.
 */
class CacheHealthService
{
    public function __construct(private CacheFactory $cache)
    {
    }

    /** @return array{status:string,store:?string,error?:string} */
    public function check(): array
    {
        $store = (string) config('cache.default');

        try {
            $repo = $this->cache->store();
            $key  = 'runtime-manager:canary';
            $value = (string) microtime(true);

            $repo->put($key, $value, 10);
            $read = $repo->get($key);
            $repo->forget($key);

            return [
                'status' => $read === $value ? 'ok' : 'fail',
                'store'  => $store,
                'error'  => $read === $value ? null : 'read-back mismatch',
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'store' => $store, 'error' => $e->getMessage()];
        }
    }
}
