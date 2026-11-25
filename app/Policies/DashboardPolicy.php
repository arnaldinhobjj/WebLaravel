<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;

/**
 * Personal dashboards are their owner's alone. Shared dashboards are seen by
 * everyone who may view dashboards and maintained by whoever may share them.
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $user->hasPermission('dashboards.view')
            && ($dashboard->isShared() || $dashboard->isOwnedBy($user));
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isShared()
            ? $user->hasPermission('dashboards.share')
            : $dashboard->isOwnedBy($user) && $user->hasPermission('dashboards.edit');
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isShared()
            ? $user->hasPermission('dashboards.share')
            : $dashboard->isOwnedBy($user) && $user->hasPermission('dashboards.delete');
    }

    /**
     * Make a personal copy to change freely.
     */
    public function copy(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard) && $user->hasPermission('dashboards.create');
    }
}
