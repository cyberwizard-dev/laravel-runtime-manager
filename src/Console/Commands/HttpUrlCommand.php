<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints the signed HTTP fallback trigger URL and a ready-to-use pinger snippet
 * for hosts without cron. The trigger must be enabled and a secret configured.
 */
class HttpUrlCommand extends Command
{
    protected $signature = 'runtime:http-url';

    protected $description = 'Show the signed HTTP fallback trigger URL and an example pinger.';

    public function handle(): int
    {
        $config = (array) config('runtime-manager.http_trigger');

        if (! ($config['enabled'] ?? false)) {
            $this->warn('HTTP trigger is disabled. Set RUNTIME_HTTP_TRIGGER_ENABLED=true and a RUNTIME_HTTP_TRIGGER_SECRET to use it.');

            return self::INVALID;
        }

        if (empty($config['secret'])) {
            $this->error('RUNTIME_HTTP_TRIGGER_SECRET is not set.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/')
            . '/' . trim((string) config('runtime-manager.dashboard.path', 'runtime'), '/') . '/trigger';

        $this->newLine();
        $this->info('Signed HTTP trigger URL:');
        $this->line('  <fg=green>' . $url . '</>');
        $this->newLine();
        $this->line('Call it once a minute from an external scheduler/uptime service. Each request must send:');
        $this->line('  <comment>X-Runtime-Timestamp: <unix seconds></comment>');
        $this->line('  <comment>X-Runtime-Signature: hmac_sha256(secret, timestamp)</comment>');
        $this->newLine();
        $this->line('Example bash pinger (run every minute):');
        $this->line(<<<BASH
  <comment>TS=\$(date +%s)
  SIG=\$(printf "%s" "\$TS" | openssl dgst -sha256 -hmac "\$RUNTIME_SECRET" | awk '{print \$2}')
  curl -s -X POST "{$url}" -H "X-Runtime-Timestamp: \$TS" -H "X-Runtime-Signature: \$SIG"</comment>
BASH);
        $this->newLine();
        $this->line('The timestamp window is ' . ($config['window_seconds'] ?? 60) . 's; each signature is accepted once (replay-protected).');
        $this->newLine();

        return self::SUCCESS;
    }
}
