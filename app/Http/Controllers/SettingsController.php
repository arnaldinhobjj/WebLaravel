<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
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

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $data['password']]);

        return redirect()->route('settings.edit', $user)->with('status', 'Password changed.');
    }
}
