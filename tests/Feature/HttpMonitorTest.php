<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HttpMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('runtime-manager.http_monitor.enabled', true);
    }

    public function test_outbound_calls_are_recorded_without_query_strings(): void
    {
        Http::fake(['example.com/*' => Http::response('ok', 200)]);

        Http::get('http://example.com/widgets?secret=shhh&token=abc');

        $row = DB::table('runtime_http_requests')->latest('id')->first();

        $this->assertNotNull($row, 'an outbound call should have been recorded');
        $this->assertSame('example.com', $row->host);
        $this->assertSame('/widgets', $row->path);
        $this->assertSame(200, (int) $row->status);
        $this->assertTrue((bool) $row->ok);

        // The secret query string must never be stored.
        $this->assertStringNotContainsString('secret', (string) $row->path);
        $this->assertStringNotContainsString('shhh', json_encode($row));
    }
}
