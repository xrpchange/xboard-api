<?php

namespace App\Http\Controllers\Arbiter;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * GET targets from operator escalation email: signed URL includes lot + action + expiry.
 */
final class ArbiterOperatorActionController extends Controller
{
    public function show(string $lot, string $action): View
    {
        Log::info('[arbiter] operator_mail_action_clicked', [
            'lot_id' => $lot,
            'action' => $action,
        ]);

        return view('arbiter.operator_action_ack', [
            'lotId' => $lot,
            'action' => $action,
        ]);
    }
}
