<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stops changes to tags, departments and people that would leave nobody able
 * to open the Tags page, which would lock everyone out of managing access.
 */
class AccessGuard
{
    /**
     * Runs the change in a transaction and undoes it if no one could manage people afterwards.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public static function keepAManager(Closure $change, string $errorField = 'permissions'): mixed
    {
        return DB::connection(config('fortvale.connections.people'))->transaction(function () use ($change, $errorField) {
            $result = $change();

            $someoneCanManage = User::with(['tags', 'department.tags'])->get()
                ->contains(fn (User $user): bool => $user->hasPermission(Permission::ManagePeople));

            if (! $someoneCanManage) {
                throw ValidationException::withMessages([
                    $errorField => 'This would leave nobody who can manage tags and people, so it wasn’t saved.',
                ]);
            }

            return $result;
        });
    }
}
