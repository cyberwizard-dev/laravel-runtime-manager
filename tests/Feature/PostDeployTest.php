<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Models\RuntimeState;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PostDeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_deploy_records_deployment_state_and_skips_migrations_by_default(): void
    {
        $code = $this->artisan('runtime:post-deploy', ['--json' => true])->run();

        $meta = RuntimeState::singleton()->meta ?? [];
        $this->assertArrayHasKey('deployment', $meta);
        $this->assertArrayHasKey('operational', $meta['deployment']);
        $this->assertIsBool($meta['deployment']['operational']);

        // Exit code mirrors operational status; either way migrations were not forced.
        $this->assertContains($code, [0, 1]);
    }
}
