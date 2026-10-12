<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Models\Traits\CompanyScoped;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use CompanyScoped;

    protected $fillable = ['name', 'manager_id', 'capacity_limit', 'parent_id', 'location_id', 'branch_id', 'company_id'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
