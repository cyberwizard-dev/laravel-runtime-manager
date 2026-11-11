<?php

/**
 * Laravel Runtime Manager — cron runner.
 *
 * Published to the application base path (one level above public_html, so it is
 * NOT web-served). Boots the Laravel kernel once, in-process, and runs a single
 * tick via `runtime:tick`. No `php artisan` subprocess is spawned and no
 * exec()/shell_exec() is required.
 *
 * cPanel cron (every minute):
 *   * * * * * /usr/local/bin/php /home/USER/APP/artisan-runtime.php >/dev/null 2>&1
 *
 * This file refuses to run under a web SAPI. The optional signed HTTP fallback
 * trigger (see config `http_trigger`) is a separate, authenticated route — not
 * this file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = __DIR__;

$autoload  = $base . '/vendor/autoload.php';
$bootstrap = $base . '/bootstrap/app.php';

if (! is_file($autoload) || ! is_file($bootstrap)) {
    fwrite(STDERR, "runtime-manager: cannot locate vendor/autoload.php or bootstrap/app.php next to the runner.\n");
    exit(1);
}

require $autoload;

/** @var \Illuminate\Foundation\Application $app */
$app = require $bootstrap;

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->call('runtime:tick', ['--trigger' => 'cli']);

$kernel->terminate(
    new \Symfony\Component\Console\Input\ArrayInput([]),
    $status
);

exit($status);
