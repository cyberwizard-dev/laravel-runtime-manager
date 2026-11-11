<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Services\HousekeepingService;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SessionGcTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.lifetime', 120);
        $app['config']->set('session.table', 'sessions');
    }

    public function test_expired_database_sessions_are_pruned(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        DB::table('sessions')->insert([
            ['id' => 'old',   'payload' => '', 'last_activity' => now()->subMinutes(200)->getTimestamp()],
            ['id' => 'fresh', 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ]);

        (new HousekeepingService((array) config('runtime-manager')))->run(microtime(true) + 5);

        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
        $this->assertDatabaseHas('sessions', ['id' => 'fresh']);
    }
}
