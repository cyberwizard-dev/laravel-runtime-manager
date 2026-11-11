<?php

namespace Cyberwizard\RuntimeManager\Services;

use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns failing health checks into alerts. Sends at most one alert per cooldown
 * window via the app's configured mail transport. If mail is misconfigured the
 * transport error is captured as a diagnostic (we never claim an alert was
 * delivered when the handoff failed) and surfaced on the dashboard.
 *
 * Detecting that the cron itself has stopped cannot be done from inside a dead
 * cron; the dashboard shows staleness when loaded, and the optional external
 * dead-man's-switch ping (see Tick) is the reliable out-of-band signal.
 */
class WatchdogService
{
    public function __construct(
        private HealthService $health,
        private Mailer $mailer,
        private array $config,
    ) {
    }

    /** @return array<string,mixed> */
    public function evaluate(): array
    {
        $failures = $this->health->failures();

        if ($failures === []) {
            return ['status' => 'healthy', 'failures' => 0];
        }

        $alerted = $this->maybeAlert($failures);

        return [
            'status'   => 'unhealthy',
            'failures' => count($failures),
            'checks'   => array_column($failures, 'key'),
            'alerted'  => $alerted,
        ];
    }

    /** @param list<array{key:string,status:string,message:string}> $failures */
    private function maybeAlert(array $failures): bool
    {
        $email = $this->config['watchdog']['alert_email'] ?? null;
        if (empty($email)) {
            return false;
        }

        if (! $this->cooldownElapsed()) {
            return false;
        }

        $app = (string) config('app.name', 'Application');
        $body = "Runtime watchdog detected failing checks for {$app}:\n\n";
        foreach ($failures as $f) {
            $body .= "• [{$f['key']}] {$f['message']}\n";
        }
        $body .= "\nDashboard: " . rtrim((string) config('app.url'), '/')
            . '/' . ltrim((string) config('runtime-manager.dashboard.path', 'runtime'), '/') . "\n";

        try {
            $this->mailer->raw($body, function ($message) use ($email, $app) {
                $message->to($email)->subject("[{$app}] Runtime watchdog alert");
            });

            $this->stampAlert(null);

            return true;
        } catch (Throwable $e) {
            // SMTP handoff failed — record the diagnostic, do not claim delivery.
            $this->stampAlert($e->getMessage());

            return false;
        }
    }

    private function cooldownElapsed(): bool
    {
        $minutes = (int) ($this->config['watchdog']['alert_cooldown_minutes'] ?? 60);

        try {
            $meta = RuntimeState::singleton()->meta ?? [];
            $last = $meta['watchdog']['last_alert_at'] ?? null;
            if (! $last) {
                return true;
            }

            return Carbon::parse($last)->addMinutes($minutes)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    private function stampAlert(?string $mailError): void
    {
        try {
            $state = RuntimeState::singleton();
            $meta = $state->meta ?? [];
            $meta['watchdog'] = [
                'last_alert_at'   => now()->toIso8601String(),
                'last_mail_error' => $mailError,
            ];
            $state->meta = $meta;
            $state->save();
        } catch (Throwable) {
            // non-fatal
        }
    }
}
