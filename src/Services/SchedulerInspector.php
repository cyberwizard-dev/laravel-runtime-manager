<?php

namespace Cyberwizard\RuntimeManager\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Read-only view of the application's scheduled tasks for the dashboard, plus
 * detection of the two features that silently degrade on shared hosting.
 */
class SchedulerInspector
{
    public function __construct(private Container $app)
    {
    }

    /** @return list<array{label:string,expression:string,next_run:?string,timezone:?string}> */
    public function tasks(): array
    {
        try {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
        } catch (Throwable) {
            return [];
        }

        return array_map(function (Event $event) {
            $next = null;
            try {
                $next = $event->nextRunDate()->toDateTimeString();
            } catch (Throwable) {
                // Expression not resolvable right now; leave null.
            }

            return [
                'label'      => method_exists($event, 'getSummaryForDisplay')
                    ? $event->getSummaryForDisplay()
                    : (string) ($event->command ?? $event->description ?? 'closure'),
                'expression' => (string) $event->expression,
                'next_run'   => $next,
                'timezone'   => $event->timezone ? (string) $event->timezone : null,
            ];
        }, $this->events($schedule));
    }

    public function count(): int
    {
        try {
            return count($this->events($this->app->make(Schedule::class)));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * `onOneServer` needs an atomic-lock-capable cache store; file/array cannot
     * provide it. Returns false when the configured store would make it a no-op.
     */
    public function onOneServerSupported(): bool
    {
        try {
            $store = $this->app->make('cache')->store()->getStore();

            return $store instanceof \Illuminate\Contracts\Cache\LockProvider;
        } catch (Throwable) {
            return false;
        }
    }

    /** `runInBackground` dispatches via a process; unavailable where exec is disabled. */
    public function runInBackgroundSupported(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    /** @return list<Event> */
    private function events(Schedule $schedule): array
    {
        return array_values($schedule->events());
    }
}
