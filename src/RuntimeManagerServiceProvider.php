<?php

namespace Cyberwizard\RuntimeManager;

use Cyberwizard\RuntimeManager\Console\Commands\CronCommandCommand;
use Cyberwizard\RuntimeManager\Console\Commands\HttpUrlCommand;
use Cyberwizard\RuntimeManager\Console\Commands\InstallCommand;
use Cyberwizard\RuntimeManager\Console\Commands\PostDeployCommand;
use Cyberwizard\RuntimeManager\Console\Commands\TickCommand;
use Cyberwizard\RuntimeManager\Services\CacheHealthService;
use Cyberwizard\RuntimeManager\Services\HealthService;
use Cyberwizard\RuntimeManager\Services\HousekeepingService;
use Cyberwizard\RuntimeManager\Services\MaintenanceService;
use Cyberwizard\RuntimeManager\Services\QueueInspector;
use Cyberwizard\RuntimeManager\Services\QueueService;
use Cyberwizard\RuntimeManager\Services\SchedulerInspector;
use Cyberwizard\RuntimeManager\Services\SchedulerService;
use Cyberwizard\RuntimeManager\Services\WatchdogService;
use Cyberwizard\RuntimeManager\Support\ServerProbe;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RuntimeManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/runtime-manager.php', 'runtime-manager');

        $config = fn (): array => (array) $this->app['config']->get('runtime-manager');

        $this->app->singleton(ServerProbe::class);

        // ── Inspectors / health ────────────────────────────────────────────
        $this->app->singleton(SchedulerInspector::class, fn ($app) => new SchedulerInspector($app));
        $this->app->singleton(QueueInspector::class, fn ($app) => new QueueInspector($app->make(DatabaseManager::class), $config()));
        $this->app->singleton(CacheHealthService::class, fn ($app) => new CacheHealthService($app->make(CacheFactory::class)));
        $this->app->singleton(HealthService::class, fn ($app) => new HealthService(
            $app,
            $app->make(CacheHealthService::class),
            $app->make(QueueInspector::class),
            $config(),
        ));

        // ── Maintenance sub-services ───────────────────────────────────────
        $this->app->bind(HousekeepingService::class, fn () => new HousekeepingService($config()));
        $this->app->bind(WatchdogService::class, fn ($app) => new WatchdogService(
            $app->make(HealthService::class),
            $app->make(Mailer::class),
            $config(),
        ));

        // ── Pipeline services (order defines tick execution order) ─────────
        $this->app->bind(SchedulerService::class, fn ($app) => new SchedulerService($app->make(ConsoleKernel::class), $config()));
        $this->app->bind(QueueService::class, fn ($app) => new QueueService(
            $app->make(ConsoleKernel::class),
            $app->make(Dispatcher::class),
            $config(),
        ));
        $this->app->bind(MaintenanceService::class, fn ($app) => new MaintenanceService(
            $app->make(HousekeepingService::class),
            $app->make(CacheHealthService::class),
            $app->make(WatchdogService::class),
            $config(),
        ));

        $this->app->tag(
            [SchedulerService::class, QueueService::class, MaintenanceService::class],
            'runtime.services',
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'runtime-manager');
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/runtime-manager.php' => config_path('runtime-manager.php'),
            ], 'runtime-manager-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'runtime-manager-migrations');

            $this->commands([
                InstallCommand::class,
                CronCommandCommand::class,
                TickCommand::class,
                PostDeployCommand::class,
                HttpUrlCommand::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        $path = (string) config('runtime-manager.dashboard.path', 'runtime');

        if ((bool) config('runtime-manager.dashboard.enabled', true)) {
            Route::group([
                'prefix'     => $path,
                'middleware' => (array) config('runtime-manager.dashboard.middleware', ['web']),
                'as'         => 'runtime.',
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/runtime.php');
            });
        }

        // Optional signed HTTP fallback trigger (no web/CSRF; authenticated by HMAC).
        if ((bool) config('runtime-manager.http_trigger.enabled', false)) {
            $throttle = (string) config('runtime-manager.http_trigger.throttle', '2,1');

            Route::match(['get', 'post'], $path . '/trigger', [\Cyberwizard\RuntimeManager\Http\Controllers\TriggerController::class, 'handle'])
                ->middleware(['throttle:' . $throttle, \Cyberwizard\RuntimeManager\Http\Middleware\VerifyRuntimeSignature::class])
                ->name('runtime.trigger');
        }
    }
}
