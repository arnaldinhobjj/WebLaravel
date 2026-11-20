<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ActionRunController extends Controller
{
    /**
     * Runs one of the person's own actions and takes them to its destination.
     */
    public function store(User $user, Action $action): RedirectResponse
    {
        $action->forceFill(['last_run_at' => now()])->save();

        if ($action->opensExternalSite()) {
            return redirect()->away($action->destination());
        }

        return redirect()->to($action->destination());
    }
}
