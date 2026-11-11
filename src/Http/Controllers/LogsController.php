<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Models\RuntimeRun;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogsController extends Controller
{
    public function index(Request $request): View
    {
        $query = RuntimeRun::query()->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($trigger = $request->query('trigger')) {
            $query->where('trigger', $trigger);
        }
        if ($date = $request->query('date')) {
            $query->whereDate('started_at', $date);
        }

        return view('runtime-manager::logs', [
            'runs'    => $query->paginate(40)->withQueryString(),
            'status'  => $status,
            'trigger' => $trigger,
            'date'    => $date,
            'statuses'=> [RuntimeRun::SUCCESS, RuntimeRun::PARTIAL, RuntimeRun::FAILED, RuntimeRun::SKIPPED],
        ]);
    }
}
