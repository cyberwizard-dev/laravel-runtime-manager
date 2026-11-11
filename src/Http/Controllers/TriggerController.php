<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Runner\Tick;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;

/**
 * Optional HTTP fallback trigger for hosts without cron. Reached only through
 * the VerifyRuntimeSignature middleware (HMAC + timestamp window + replay
 * nonce + rate limit). Runs exactly one tick, same as the CLI runner.
 */
class TriggerController extends Controller
{
    public function handle(Container $app, CacheFactory $cache): JsonResponse
    {
        $services = iterator_to_array($app->tagged('runtime.services'));

        $tick = new Tick($cache, (array) config('runtime-manager'), $services);
        $result = $tick->run('http');

        return response()->json([
            'status'      => $result['status'],
            'duration_ms' => $result['duration_ms'],
        ]);
    }
}
