<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mail.default', 'array');          // real send, collected in-memory
        $app['config']->set('runtime-manager.mail_monitor.enabled', true);
    }

    public function test_sent_mail_is_recorded_without_body_or_full_address(): void
    {
        Mail::raw('Top secret message body', function ($m) {
            $m->to('alice@example.com')->subject('Welcome aboard');
        });

        $row = DB::table('runtime_mail_log')->latest('id')->first();

        $this->assertNotNull($row, 'an outgoing message should have been recorded');
        $this->assertSame('example.com', $row->to_domains);
        $this->assertSame(1, (int) $row->recipients);
        $this->assertSame('Welcome aboard', $row->subject);
        $this->assertSame('sent', $row->status);

        // Neither the body nor the full address is stored.
        $encoded = json_encode($row);
        $this->assertStringNotContainsString('Top secret', $encoded);
        $this->assertStringNotContainsString('alice@example.com', $encoded);
    }
}
