<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Models\RuntimeAudit;
use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Services\QueueInspector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueController extends Controller
{
    public function index(QueueInspector $queues): View
    {
        $history = RuntimeRun::query()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (RuntimeRun $run) {
                $q = $run->services['queue']['summary'] ?? null;

                return [
                    'at'        => $run->started_at,
                    'status'    => $run->status,
                    'processed' => $q['processed'] ?? null,
                    'failed'    => $q['failed'] ?? null,
                    'duration'  => $run->duration_ms,
                ];
            });

        return view('runtime-manager::queue', [
            'pendingByQ' => $queues->pendingByQueue(),
            'pending'    => $queues->pendingTotal(),
            'reserved'   => $queues->reservedTotal(),
            'failed'     => $queues->recentFailed(),
            'failedTotal'=> $queues->failedTotal(),
            'history'    => $history,
        ]);
    }

    public function retry(Request $request, Kernel $kernel): RedirectResponse
    {
        $uuid = $request->input('uuid');
        $kernel->call('queue:retry', ['id' => $uuid ? [$uuid] : ['all']]);
        RuntimeAudit::record('queue.retry', $uuid ?: 'all', [], $request->ip());

        return back()->with('status', $uuid ? "Retried job {$uuid}." : 'Retried all failed jobs.');
    }

    public function forget(Request $request, Kernel $kernel, string $uuid): RedirectResponse
    {
        $kernel->call('queue:forget', ['id' => $uuid]);
        RuntimeAudit::record('queue.forget', $uuid, [], $request->ip());

        return back()->with('status', "Removed failed job {$uuid}.");
    }

    public function flush(Request $request, Kernel $kernel): RedirectResponse
    {
        $kernel->call('queue:flush');
        RuntimeAudit::record('queue.flush', 'all', [], $request->ip());

        return back()->with('status', 'Flushed all failed jobs.');
    }
}
