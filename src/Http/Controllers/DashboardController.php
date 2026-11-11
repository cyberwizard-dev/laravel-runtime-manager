<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Services\HealthService;
use Cyberwizard\RuntimeManager\Services\QueueInspector;
use Cyberwizard\RuntimeManager\Services\SchedulerInspector;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function overview(
        HealthService $health,
        QueueInspector $queues,
        SchedulerInspector $scheduler,
    ): View {
        $state = RuntimeState::query()->find(1);
        $meta = $state?->meta ?? [];
        $staleAfter = (int) config('runtime-manager.watchdog.stale_after_seconds', 180);

        $recentRuns = RuntimeRun::query()->orderByDesc('id')->limit(15)->get();

        $avgDuration = (int) RuntimeRun::query()
            ->whereIn('status', [RuntimeRun::SUCCESS, RuntimeRun::PARTIAL])
            ->where('started_at', '>=', now()->subHour())
            ->avg('duration_ms');

        return view('runtime-manager::overview', [
            'state'        => $state,
            'stale'        => $state?->isStale($staleAfter) ?? true,
            'staleAfter'   => $staleAfter,
            'health'       => $health->report(),
            'pending'      => $queues->pendingTotal(),
            'pendingByQ'   => $queues->pendingByQueue(),
            'reserved'     => $queues->reservedTotal(),
            'failed'       => $queues->failedTotal(),
            'schedCount'   => $scheduler->count(),
            'recentRuns'   => $recentRuns,
            'avgDuration'  => $avgDuration,
            'deployment'   => $meta['deployment'] ?? null,
            'mailError'    => $meta['watchdog']['last_mail_error'] ?? null,
        ]);
    }
}
