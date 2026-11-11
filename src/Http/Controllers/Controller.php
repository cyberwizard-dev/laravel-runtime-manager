<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    protected function dashboardPath(): string
    {
        return (string) config('runtime-manager.dashboard.path', 'runtime');
    }
}
