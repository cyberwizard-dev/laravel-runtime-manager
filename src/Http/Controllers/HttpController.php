<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Services\HttpMonitorInspector;
use Illuminate\View\View;

class HttpController extends Controller
{
    public function index(HttpMonitorInspector $monitor): View
    {
        return view('runtime-manager::http', [
            'enabled'  => $monitor->enabled(),
            'total'    => $monitor->totalLastHour(),
            'failures' => $monitor->failuresLastHour(),
            'avg'      => $monitor->avgDurationLastHour(),
            'recent'   => $monitor->recent(),
        ]);
    }
}
