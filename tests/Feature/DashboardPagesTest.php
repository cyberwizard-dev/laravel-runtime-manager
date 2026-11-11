<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Http\Middleware\DashboardAuth;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Every authenticated dashboard page compiles and renders. */
    public function test_pages_render(): void
    {
        $session = [DashboardAuth::SESSION_KEY => true];

        $this->withSession($session)->get('/runtime')->assertOk()->assertSee('Overview');
        $this->withSession($session)->get('/runtime/queue')->assertOk()->assertSee('Queue');
        $this->withSession($session)->get('/runtime/scheduler')->assertOk()->assertSee('Scheduler');
        $this->withSession($session)->get('/runtime/http')->assertOk()->assertSee('Outgoing HTTP');
        $this->withSession($session)->get('/runtime/mail')->assertOk()->assertSee('Outgoing mail');
        $this->withSession($session)->get('/runtime/logs')->assertOk()->assertSee('Run logs');
        $this->withSession($session)->get('/runtime/setup')->assertOk()->assertSee('Cron command');
    }
}
