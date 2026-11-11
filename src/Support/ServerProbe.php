<?php

namespace Cyberwizard\RuntimeManager\Support;

/**
 * Lightweight environment detection used by the installer and cron-command
 * generator. The full setup-wizard probe (Phase 5) will extend this; Phase 0
 * only needs the PHP CLI binary, the runner path, and a couple of capability
 * flags so it can print an accurate cron line.
 */
class ServerProbe
{
    /**
     * Best-effort absolute path to the PHP **CLI** binary for the cron entry.
     *
     * Under the CLI SAPI, PHP_BINARY is already the CLI binary. Under web/FPM
     * it points at php-fpm, so we fall back to scanning common shared-host
     * locations and finally to a bare "php".
     */
    public function phpCliBinary(): string
    {
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        foreach ($this->phpCliCandidates() as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /** @return list<string> */
    public function phpCliCandidates(): array
    {
        $version = PHP_MAJOR_VERSION . PHP_MINOR_VERSION; // e.g. "83"

        return array_values(array_unique(array_filter([
            '/usr/local/bin/php',
            '/usr/bin/php',
            "/opt/cpanel/ea-php{$version}/root/usr/bin/php",     // cPanel EasyApache
            "/opt/alt/php{$version}/usr/bin/php",                 // CloudLinux alt-php
            "/usr/local/bin/php{$version}",
            "/usr/local/bin/ea-php{$version}",
            getenv('PHP_BINARY') ?: null,
        ])));
    }

    /**
     * Where the published runner lives. Convention: the application base path,
     * which on most shared hosts is one level above public_html and therefore
     * NOT web-served.
     */
    public function runnerPath(string $basePath): string
    {
        return rtrim($basePath, '/') . '/artisan-runtime.php';
    }

    /** Exact cron line to paste into cPanel. */
    public function cronCommand(string $basePath): string
    {
        $php    = $this->phpCliBinary();
        $runner = $this->runnerPath($basePath);

        return sprintf('* * * * * %s %s >/dev/null 2>&1', $this->shellArg($php), $this->shellArg($runner));
    }

    /** @return list<string> Names of disabled process functions. */
    public function disabledProcessFunctions(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return array_values(array_intersect(
            ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru', 'pcntl_fork'],
            array_filter($disabled)
        ));
    }

    public function hasProcessControl(): bool
    {
        return function_exists('pcntl_async_signals') && ! in_array('pcntl_fork', $this->disabledProcessFunctions(), true);
    }

    private function shellArg(string $value): string
    {
        // Quote only when needed; keep bare tokens like "php" clean.
        return preg_match('/^[A-Za-z0-9._\/-]+$/', $value) === 1
            ? $value
            : "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
