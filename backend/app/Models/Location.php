<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Models\Traits\CompanyScoped;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use CompanyScoped;

    protected $fillable = ['name', 'company_name', 'address', 'floor', 'phone', 'is_active', 'company_id'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'location_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
}
