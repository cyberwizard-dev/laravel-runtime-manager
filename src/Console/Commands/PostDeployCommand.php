<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Services\HealthService;
use Cyberwizard\RuntimeManager\Services\QueueInspector;
use Cyberwizard\RuntimeManager\Services\SchedulerInspector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operational post-deployment pipeline, designed to be added to the FTP
 * deployer's `post_extraction_commands` (or run manually).
 *
 * It deliberately does NOT re-run the cache/route/config optimisation or the
 * migrations that laravel-ftp-deployer already performs during extraction.
 * Instead it verifies the app is operational after a deploy and records that
 * state. Migrations run ONLY when --migrate is explicitly passed, so this
 * command is safe to run unconditionally on every deploy.
 */
class PostDeployCommand extends Command
{
    protected $signature = 'runtime:post-deploy
        {--migrate : Run database migrations (off by default for safety)}
        {--json : Output machine-readable JSON}';

    protected $description = 'Verify and mark the application operational after a deployment.';

    public function handle(HealthService $health, SchedulerInspector $scheduler, QueueInspector $queues): int
    {
        $steps = [];

        $steps['storage_link'] = $this->ensureStorageLink();

        if ($this->option('migrate')) {
            $steps['migrate'] = $this->runMigrations();
        } else {
            $steps['migrate'] = ['status' => 'skipped', 'note' => 'pass --migrate to enable'];
        }

        $steps['scheduler'] = ['status' => 'ok', 'tasks' => $scheduler->count()];
        $steps['queue']     = ['status' => 'ok', 'pending' => $queues->pendingTotal(), 'failed' => $queues->failedTotal()];

        $report = $health->report();
        $failures = array_values(array_filter($report, fn ($c) => $c['status'] === HealthService::FAIL));
        $steps['health'] = ['status' => $failures === [] ? 'ok' : 'fail', 'failures' => array_column($failures, 'key')];

        $operational = $failures === []
            && ($steps['storage_link']['status'] ?? 'ok') !== 'fail'
            && ($steps['migrate']['status'] ?? 'ok') !== 'fail';

        $this->markOperational($operational, $steps);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'operational' => $operational,
                'steps'       => $steps,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $operational ? self::SUCCESS : self::FAILURE;
        }

        $this->renderTable($steps);
        $operational
            ? $this->info('Application marked OPERATIONAL.')
            : $this->error('Post-deploy checks failed — see above. Application NOT marked operational.');

        return $operational ? self::SUCCESS : self::FAILURE;
    }

    private function ensureStorageLink(): array
    {
        $link = public_path('storage');

        try {
            if (file_exists($link)) {
                return ['status' => 'ok', 'note' => 'already linked'];
            }

            $this->callSilent('storage:link');

            return file_exists($link)
                ? ['status' => 'ok', 'note' => 'created']
                : ['status' => 'warn', 'note' => 'could not create (symlinks may be disabled)'];
        } catch (Throwable $e) {
            return ['status' => 'warn', 'note' => $e->getMessage()];
        }
    }

    private function runMigrations(): array
    {
        try {
            $code = $this->call('migrate', ['--force' => true]);

            return ['status' => $code === 0 ? 'ok' : 'fail', 'exit_code' => $code];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    private function markOperational(bool $operational, array $steps): void
    {
        try {
            $state = RuntimeState::singleton();
            $meta = $state->meta ?? [];
            $meta['deployment'] = [
                'at'          => now()->toIso8601String(),
                'operational' => $operational,
            ];
            $state->meta = $meta;
            $state->save();
        } catch (Throwable) {
            // Non-fatal; the command's exit code still reports the outcome.
        }
    }

    private function renderTable(array $steps): void
    {
        $rows = [];
        foreach ($steps as $name => $data) {
            $rows[] = [$name, $data['status'] ?? '?', $this->describe($data)];
        }
        $this->table(['Step', 'Status', 'Detail'], $rows);
    }

    private function describe(array $data): string
    {
        unset($data['status']);

        return collect($data)
            ->map(fn ($v, $k) => $k . '=' . (is_array($v) ? implode('|', $v) : (string) $v))
            ->implode(', ');
    }
}
