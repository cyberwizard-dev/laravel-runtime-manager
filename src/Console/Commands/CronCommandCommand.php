<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Cyberwizard\RuntimeManager\Support\ServerProbe;
use Illuminate\Console\Command;

/**
 * Prints the exact single cron line to paste into cPanel (or the provider's
 * cron UI), plus the detected PHP CLI binary and runner path so the developer
 * can sanity-check them.
 */
class CronCommandCommand extends Command
{
    protected $signature = 'runtime:cron-command {--json : Output machine-readable JSON}';

    protected $description = 'Show the one cron command to configure on the host.';

    public function handle(ServerProbe $probe): int
    {
        $base    = base_path();
        $php     = $probe->phpCliBinary();
        $runner  = $probe->runnerPath($base);
        $command = $probe->cronCommand($base);
        $disabled = $probe->disabledProcessFunctions();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'php_binary'         => $php,
                'runner_path'        => $runner,
                'cron_command'       => $command,
                'disabled_functions' => $disabled,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Add this single cron job in cPanel → Cron Jobs (interval: every minute):');
        $this->newLine();
        $this->line('  <fg=green>' . $command . '</>');
        $this->newLine();
        $this->line('  Detected PHP CLI binary : <comment>' . $php . '</comment>');
        $this->line('  Runner path             : <comment>' . $runner . '</comment>');

        if (! file_exists($runner)) {
            $this->newLine();
            $this->warn('  Runner not published yet. Run `php artisan runtime:install` first.');
        }

        if ($php === 'php') {
            $this->newLine();
            $this->warn('  Could not resolve an absolute PHP CLI path. If the cron fails, ask your host');
            $this->warn('  for the correct PHP CLI binary path and substitute it above.');
        }

        if ($disabled !== []) {
            $this->newLine();
            $this->line('  Disabled process functions: <comment>' . implode(', ', $disabled) . '</comment>');
            $this->line('  (The runner boots Laravel in-process and does not require these.)');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
