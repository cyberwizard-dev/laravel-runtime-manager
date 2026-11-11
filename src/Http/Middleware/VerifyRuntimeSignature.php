<?php

namespace Cyberwizard\RuntimeManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the optional HTTP fallback trigger (for hosts without cron, or
 * to drive the tick from an external cron service). Two modes are supported;
 * either is sufficient:
 *
 * 1. TOKEN mode — a long unguessable token sent as `?token=...` or the
 *    `X-Runtime-Token` header. This works with ANY "ping this URL every minute"
 *    service (cron-job.org, EasyCron, UptimeRobot, GitHub Actions cron, …) that
 *    cannot compute a per-request signature. Compared in constant time.
 *
 * 2. HMAC mode — `X-Runtime-Timestamp` + `X-Runtime-Signature:
 *    hmac_sha256(secret, timestamp)`, with a timestamp window and one-time
 *    replay nonce, for pingers that can sign each request (most secure).
 *
 * The route is rate-limited regardless, and the endpoint only runs a tick and
 * returns {status, duration_ms} — it exposes no application data.
 */
class VerifyRuntimeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('runtime-manager.http_trigger');

        if (! ($config['enabled'] ?? false)) {
            abort(404);
        }

        $token  = (string) ($config['token'] ?? '');
        $secret = (string) ($config['secret'] ?? '');

        if ($token === '' && $secret === '') {
            abort(503, 'HTTP trigger has no token or secret configured.');
        }

        // --- Token mode -----------------------------------------------------
        if ($token !== '') {
            $provided = (string) $request->query('token', '');
            if ($provided === '') {
                $provided = (string) $request->header('X-Runtime-Token', '');
            }
            if ($provided !== '' && hash_equals($token, $provided)) {
                return $next($request);
            }
            // If no HMAC secret is configured, a token mismatch is the end of the line.
            if ($secret === '') {
                abort(401, 'Invalid token.');
            }
        }

        // --- HMAC mode ------------------------------------------------------
        $timestamp = (string) $request->header('X-Runtime-Timestamp', '');
        $signature = (string) $request->header('X-Runtime-Signature', '');
        $window    = (int) ($config['window_seconds'] ?? 60);

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            abort(401, 'Missing credentials.');
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
