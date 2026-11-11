<?php

namespace Cyberwizard\RuntimeManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the optional HTTP fallback trigger (for hosts without cron).
 *
 * The external pinger must send:
 *   X-Runtime-Timestamp: <unix seconds>
 *   X-Runtime-Signature: hmac_sha256(secret, timestamp)
 *
 * We verify the HMAC with a constant-time comparison, require the timestamp to
 * be within a short window (clock-skew tolerant, replay-limiting), and record a
 * one-time nonce for the window so a captured request cannot be replayed. The
 * route is additionally rate-limited. Unlike the deployer's one-time deploy
 * token, this is a stable secret because the trigger recurs every minute.
 */
class VerifyRuntimeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('runtime-manager.http_trigger');

        if (! ($config['enabled'] ?? false)) {
            abort(404);
        }

        $secret = (string) ($config['secret'] ?? '');
        if ($secret === '') {
            abort(503, 'HTTP trigger secret not configured.');
        }

        $timestamp = (string) $request->header('X-Runtime-Timestamp', '');
        $signature = (string) $request->header('X-Runtime-Signature', '');
        $window    = (int) ($config['window_seconds'] ?? 60);

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            abort(401, 'Missing signature.');
        }

        if (abs(time() - (int) $timestamp) > $window) {
            abort(401, 'Stale timestamp.');
        }

        $expected = hash_hmac('sha256', $timestamp, $secret);
        if (! hash_equals($expected, $signature)) {
            abort(401, 'Bad signature.');
        }

        // Replay protection: the signature may be used once within its window.
        $nonceKey = 'runtime-manager:trigger-nonce:' . $signature;
        if (! Cache::add($nonceKey, 1, $window + 5)) {
            abort(409, 'Replay detected.');
        }

        return $next($request);
    }
}
