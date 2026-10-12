<?php

namespace App\Policies;

use App\Models\User;

class CompanyPolicy
{
    public function before($user, $ability)
    {
        if ($user->hasRole('system_admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, $resource)
    {
        if (method_exists($resource, 'getCompanyId')) {
            return $user->company_id == $resource->getCompanyId();
        }

        return false;
    }

    public function create(User $user)
    {
        return $user->company_id !== null;
    }

    public function update(User $user, $resource)
    {
        return $this->view($user, $resource);
    }

    public function delete(User $user, $resource)
    {
        if (!$user->hasRole('system_admin') && !$user->isAdmin()) {
            return false;
        }

        return $this->view($user, $resource);
    }
}
