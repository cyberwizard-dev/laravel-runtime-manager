<?php

namespace Cyberwizard\RuntimeManager\Http\Controllers;

use Cyberwizard\RuntimeManager\Services\MailMonitorInspector;
use Illuminate\View\View;

class MailController extends Controller
{
    public function index(MailMonitorInspector $mail): View
    {
        return view('runtime-manager::mail', [
            'enabled' => $mail->enabled(),
            'sent'    => $mail->sentLastDay(),
            'recent'  => $mail->recent(),
        ]);
    }
}
