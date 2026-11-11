<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Cyberwizard\RuntimeManager\Support\ServerProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-shot installer:
 *   1. Publishes the config file.
 *   2. Publishes runner.php into the application base path (outside the web root).
 *   3. Runs the package migrations (opt-in via --migrate or prompt).
 *   4. Establishes the standalone dashboard password (hashed) in .env.
 *   5. Prints the cron command to finish setup.
 *
 * Safe to re-run; it will not clobber an existing runner or password unless
 * --force is given.
 */
class InstallCommand extends Command
{
    protected $signature = 'runtime:install
        {--migrate : Run the runtime migrations now}
        {--password= : Set the dashboard password (otherwise a random one is generated)}
        {--force : Overwrite an existing runner.php and dashboard password}';

    protected $description = 'Install the runtime manager: publish config + runner, migrate, set dashboard password.';

    public function handle(ServerProbe $probe): int
    {
        $this->info('Installing Laravel Runtime Manager…');

        $this->publishConfig();
        $this->publishRunner($probe);
        $this->maybeMigrate();
        $this->ensureDashboardPassword();

        $this->newLine();
        $this->info('Done. Final step — add the cron job:');
        $this->call('runtime:cron-command');

        $this->line('Then open: <comment>' . rtrim((string) config('app.url'), '/') . '/' . ltrim((string) config('runtime-manager.dashboard.path', 'runtime'), '/') . '</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        if (file_exists(config_path('runtime-manager.php')) && ! $this->option('force')) {
            $this->line('  • Config already published, leaving as-is.');

            return;
        }

        $this->callSilent('vendor:publish', [
            '--tag'   => 'runtime-manager-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->line('  • Published config/runtime-manager.php');
    }

    private function publishRunner(ServerProbe $probe): void
    {
        $target = $probe->runnerPath(base_path());

        if (file_exists($target) && ! $this->option('force')) {
            $this->line('  • Runner already present at ' . $target);

            return;
        }

        $stub = file_get_contents(__DIR__ . '/../../../stubs/runner.php');
        file_put_contents($target, $stub);
        @chmod($target, 0755);

        $this->line('  • Published runner → ' . $target);
    }

    private function maybeMigrate(): void
    {
        $run = $this->option('migrate') || $this->confirm('Run the runtime migrations now?', true);

        if ($run) {
            $this->call('migrate', ['--force' => true]);
        } else {
            $this->line('  • Skipped migrations — run `php artisan migrate` before first use.');
        }
    }

    private function ensureDashboardPassword(): void
    {
        if (config('runtime-manager.dashboard.password_hash') && ! $this->option('force')) {
            $this->line('  • Dashboard password already set.');

            return;
        }

        $plain = (string) ($this->option('password') ?: Str::password(16));
        $hash  = Hash::make($plain);

        $written = $this->upsertEnv('RUNTIME_DASHBOARD_PASSWORD', $hash);

        $this->newLine();
        if ($written) {
            $this->line('  • Dashboard password hash written to .env');
        } else {
            $this->warn('  • Could not write .env automatically. Add this line yourself:');
            $this->line("      RUNTIME_DASHBOARD_PASSWORD='" . $hash . "'");
        }

        if (! $this->option('password')) {
            $this->newLine();
            $this->warn('  Dashboard password (shown once — store it now):');
            $this->line('      <fg=green>' . $plain . '</>');
        }
    }

    /** Upsert a KEY="value" pair in the project .env. Returns false if .env is unwritable. */
    private function upsertEnv(string $key, string $value): bool
    {
        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        // Single quotes keep the value literal: bcrypt hashes contain "$...."
        // segments that phpdotenv would otherwise treat as variable interpolation.
        $line     = $key . "='" . $value . "'";
        $pattern  = '/^' . preg_quote($key, '/') . '=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n") . "\n" . $line . "\n";

        return file_put_contents($path, $contents) !== false;
    }
}
