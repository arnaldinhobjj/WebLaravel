<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal pages under /{username} only open for the person you are working as.
 */
class EnsureCurrentUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $owner = $request->route('user');

        abort_unless(
            $owner instanceof User && $owner->is(CurrentUser::get()),
            403,
            'This page belongs to someone else.',
        );

        return $next($request);
    }
}
