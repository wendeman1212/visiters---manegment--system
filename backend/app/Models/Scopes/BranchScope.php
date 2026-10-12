<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (app()->runningInConsole()) {
            return;
        }

        $user = auth()->user();

        if ($user && $user->branch_id && !$user->isAdmin()) {
            $builder->where('branch_id', $user->branch_id);
        }
    }
}
