<?php

namespace Cyberwizard\RuntimeManager\Console\Commands;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Runner\Tick;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Per-minute tick entry point, invoked in-process by the published runner.php
 * (and runnable by hand for verification). Thin wrapper over {@see Tick}.
 */
class TickCommand extends Command
{
    protected $signature = 'runtime:tick {--trigger=cli : How the tick was triggered (cli|http)}';

    protected $description = 'Run one runtime tick: scheduler, queues and maintenance within the execution budget.';

    public function handle(CacheFactory $cache): int
    {
        /** @var iterable $services */
        $services = $this->laravel->tagged('runtime.services');

        $tick = new Tick($cache, (array) config('runtime-manager'), iterator_to_array($services));

        $result = $tick->run($this->option('trigger') === 'http' ? 'http' : 'cli');

        if (($result['skipped'] ?? false) && ! ($result['status'] === RuntimeRun::SKIPPED && ! config('runtime-manager.enabled', true))) {
            $this->line('Tick skipped (lock held or disabled).');
        } else {
            $this->line(sprintf('Tick %s in %dms.', $result['status'], $result['duration_ms']));
        }

        return $result['status'] === RuntimeRun::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
