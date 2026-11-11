<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Support\ServerProbe;
use Cyberwizard\RuntimeManager\Tests\TestCase;

class CronCommandTest extends TestCase
{
    public function test_it_prints_a_single_minutely_cron_line(): void
    {
        $this->artisan('runtime:cron-command')
            ->expectsOutputToContain('* * * * *')
            ->expectsOutputToContain('artisan-runtime.php')
            ->assertSuccessful();
    }

    public function test_probe_builds_a_runner_path_at_the_base(): void
    {
        $probe = $this->app->make(ServerProbe::class);

        $this->assertStringEndsWith('/artisan-runtime.php', $probe->runnerPath('/home/user/app'));
        $this->assertStringContainsString('* * * * *', $probe->cronCommand('/home/user/app'));
    }
}
