<?php

namespace Cyberwizard\RuntimeManager\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class IncrementCacheJob implements ShouldQueue
{
    use Queueable;

    public const KEY = 'runtime-test:processed';

    public function handle(): void
    {
        Cache::increment(self::KEY);
    }
}
