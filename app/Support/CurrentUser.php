<?php

namespace App\Support;

use App\Models\User;

/**
 * The person using the app. Fort Vale has no login screen, so the profile
 * menu lets you switch between team members; the choice lives in the session.
 */
class CurrentUser
{
    private const CACHE_KEY = 'fortvale.current_user';

    public static function get(): User
    {
        $attributes = request()->attributes;
        $id = session('acting_user_id');
        $cached = $attributes->get(self::CACHE_KEY);

        if ($cached instanceof User && $cached->id === $id) {
            return $cached;
        }

        $user = ($id ? User::find($id) : null)
            ?? User::orderBy('id')->first()
            ?? User::create([
                'name' => 'Alex Morgan',
                'email' => 'alex.morgan@fortvale.test',
                'job_title' => 'Operations lead',
                'password' => str()->random(32),
            ]);

        session(['acting_user_id' => $user->id]);
        $attributes->set(self::CACHE_KEY, $user);

        return $user;
    }

    public static function switchTo(User $user): void
    {
        session(['acting_user_id' => $user->id]);
        request()->attributes->set(self::CACHE_KEY, $user);
    }
}
