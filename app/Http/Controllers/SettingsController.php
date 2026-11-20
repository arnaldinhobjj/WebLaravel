<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(User $user): View
    {
        return view('settings.edit', ['user' => $user]);
    }

    public function update(UpdateSettingsRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        return redirect()
            ->route('settings.edit', $user)
            ->with('status', 'Settings saved.');
    }
}
