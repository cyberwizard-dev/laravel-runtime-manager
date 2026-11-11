<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints an external cron URL you can hand to any "ping this URL every minute"
 * service. If no token is configured yet it generates one, enables the trigger,
 * and writes both to .env (then reminds you to reload cached config).
 */
class HttpUrlCommand extends Command
{
    protected $signature = 'runtime:http-url
        {--regenerate : Replace the existing token with a new one}';

    protected $description = 'Show (and set up) the external URL an online cron service can call every minute.';

    public function handle(): int
    {
        $token = (string) config('runtime-manager.http_trigger.token', '');
        $enabled = (bool) config('runtime-manager.http_trigger.enabled', false);
        $wrote = [];

        if ($token === '' || $this->option('regenerate')) {
            $token = Str::random(48);
            $wrote['RUNTIME_HTTP_TRIGGER_TOKEN'] = $token;
        }

        if (! $enabled) {
            $wrote['RUNTIME_HTTP_TRIGGER_ENABLED'] = 'true';
        }

        $envWritten = true;
        foreach ($wrote as $key => $value) {
            $envWritten = $this->upsertEnv($key, $value) && $envWritten;
        }

        $base = rtrim((string) config('app.url'), '/')
            . '/' . trim((string) config('runtime-manager.dashboard.path', 'runtime'), '/') . '/trigger';
        $url = $base . '?token=' . $token;

        $this->newLine();
        $this->info('External cron URL (call this once a minute):');
        $this->line('  <fg=green>' . $url . '</>');
        $this->newLine();
        $this->line('Use it with any cron/uptime service (cron-job.org, EasyCron, UptimeRobot, GitHub Actions cron, …).');
        $this->line('Method GET or POST. Prefer sending the token as the <comment>X-Runtime-Token</comment> header');
        $this->line('instead of the query string when your service supports custom headers (keeps it out of logs).');
        $this->newLine();

        if ($wrote !== []) {
            if ($envWritten) {
                $this->line('Wrote to .env: <comment>' . implode(', ', array_keys($wrote)) . '</comment>');
                $this->warn('Reload cached config for it to take effect:  php artisan config:clear   (or config:cache)');
            } else {
                $this->warn('Could not write .env automatically. Add these lines yourself:');
                foreach ($wrote as $k => $v) {
                    $this->line("  {$k}=" . (str_contains($v, ' ') ? "'{$v}'" : $v));
                }
            }
            $this->newLine();
        }

        $this->line('Security: the token is unguessable and the endpoint only runs a tick (no data exposed),');
        $this->line('but keep it secret, always use HTTPS, and the route is rate-limited. Rotate with --regenerate.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** Upsert a KEY=value pair in .env (single-quoted when needed). */
    private function upsertEnv(string $key, string $value): bool
    {
        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $line     = $key . '=' . (preg_match('/[\s$]/', $value) ? "'" . $value . "'" : $value);
        $pattern  = '/^' . preg_quote($key, '/') . '=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n") . "\n" . $line . "\n";

        return file_put_contents($path, $contents) !== false;
    }
}
