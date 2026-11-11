<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Http\Middleware\DashboardAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('runtime-manager::login', [
            'configured' => ! empty(config('runtime-manager.dashboard.password_hash')),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $hash = (string) config('runtime-manager.dashboard.password_hash', '');
        [$maxAttempts, $decayMinutes] = $this->throttleSettings();
        $key = 'runtime-login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(['password' => "Too many attempts. Try again in {$seconds}s."]);
        }

        $request->validate(['password' => ['required', 'string']]);

        if ($hash === '' || ! Hash::check($request->input('password'), $hash)) {
            RateLimiter::hit($key, $decayMinutes * 60);

            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        RateLimiter::clear($key);
        $request->session()->put(DashboardAuth::SESSION_KEY, true);
        $request->session()->regenerate();

        return redirect()->route('runtime.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(DashboardAuth::SESSION_KEY);

        return redirect()->route('runtime.login');
    }

    /** @return array{0:int,1:int} */
    private function throttleSettings(): array
    {
        $raw = (string) config('runtime-manager.dashboard.login_throttle', '5,1');
        $parts = array_map('intval', explode(',', $raw) + [0 => 5, 1 => 1]);

        return [$parts[0] ?: 5, $parts[1] ?: 1];
    }
}
