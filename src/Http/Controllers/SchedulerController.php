<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Cyberwizard\RuntimeManager\Services\SchedulerInspector;
use Illuminate\View\View;

class SchedulerController extends Controller
{
    public function index(SchedulerInspector $scheduler): View
    {
        $lastSchedulerRun = RuntimeRun::query()
            ->whereNotNull('services')
            ->orderByDesc('id')
            ->get()
            ->first(fn (RuntimeRun $run) => isset($run->services['scheduler']));

        return view('runtime-manager::scheduler', [
            'tasks'              => $scheduler->tasks(),
            'onOneServer'        => $scheduler->onOneServerSupported(),
            'runInBackground'    => $scheduler->runInBackgroundSupported(),
            'lastRun'            => $lastSchedulerRun,
            'lastSchedulerData'  => $lastSchedulerRun->services['scheduler'] ?? null,
        ]);
    }
}
