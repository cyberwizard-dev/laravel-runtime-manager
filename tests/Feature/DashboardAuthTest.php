<?php

namespace Cyberwizard\RuntimeManager\Tests\Feature;

use Cyberwizard\RuntimeManager\Http\Middleware\DashboardAuth;
use Cyberwizard\RuntimeManager\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class DashboardAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('runtime-manager.dashboard.password_hash', Hash::make('s3cret'));
    }

    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $this->get('/runtime')->assertRedirect('/runtime/login');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/runtime/login', ['password' => 'nope'])
            ->assertSessionHasErrors('password');

        $this->assertFalse(session()->get(DashboardAuth::SESSION_KEY) === true);
    }

    public function test_correct_password_grants_access(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/runtime/login', ['password' => 's3cret'])
            ->assertRedirect(route('runtime.dashboard'));

        $this->withSession([DashboardAuth::SESSION_KEY => true])
            ->get('/runtime')
            ->assertOk()
            ->assertSee('Overview');
    }
}
