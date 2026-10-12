<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

class CompanyScope implements Scope
{
    protected static array $hasCompanyColumn = [];

    public function apply(Builder $builder, Model $model)
    {
        $user = auth('web')->getUser();

        if (!$user || !$user->company_id) {
            return;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('system_admin')) {
            return;
        }

        $table = $model->getTable();

        if (!array_key_exists($table, static::$hasCompanyColumn)) {
            static::$hasCompanyColumn[$table] = Schema::hasColumn($table, 'company_id');
        }

        if (static::$hasCompanyColumn[$table]) {
            $builder->where($table . '.company_id', $user->company_id);
        }
    }
}
