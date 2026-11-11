<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

/**
 * Optional, off-by-default monitoring of the application's OUTGOING HTTP calls
 * made through Laravel's HTTP client. Listens to the client's own events and
 * records only destination host, path, status, duration and failures.
 *
 * It deliberately never stores query strings, headers, credentials or request/
 * response bodies, so enabling it does not leak secrets into the database.
 */
class HttpMonitor
{
    /** @var array<int,float> request object id => start time */
    private array $starts = [];

    public function __construct(private DatabaseManager $db)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, function (RequestSending $e) {
            $this->starts[spl_object_id($e->request)] = microtime(true);
        });

        $events->listen(ResponseReceived::class, function (ResponseReceived $e) {
            $this->record($e->request, $e->response->status(), $e->response->successful(), null);
        });

        $events->listen(ConnectionFailed::class, function (ConnectionFailed $e) {
            $error = property_exists($e, 'exception') && $e->exception ? $e->exception->getMessage() : 'connection failed';
            $this->record($e->request, null, false, $error);
        });
    }

    private function record(object $request, ?int $status, bool $ok, ?string $error): void
    {
        try {
            $id = spl_object_id($request);
            $start = $this->starts[$id] ?? null;
            unset($this->starts[$id]);

            $url = method_exists($request, 'url') ? (string) $request->url() : '';
            $parts = parse_url($url) ?: [];

            $this->db->table('runtime_http_requests')->insert([
                'method'      => method_exists($request, 'method') ? strtoupper((string) $request->method()) : 'GET',
                'host'        => $parts['host'] ?? 'unknown',
                'path'        => $parts['path'] ?? '/',           // query stripped
                'status'      => $status,
                'duration_ms' => $start !== null ? (int) ((microtime(true) - $start) * 1000) : null,
                'ok'          => $ok,
                'error'       => $error,
                'created_at'  => now(),
            ]);
        } catch (Throwable) {
            // Monitoring must never disrupt the application's own HTTP calls.
        }
    }
}
