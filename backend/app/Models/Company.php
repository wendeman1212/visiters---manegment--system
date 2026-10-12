<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $primaryKey = 'company_id';

    public $timestamps = false;

    protected $fillable = [
        'company_name',
        'code',
        'industry',
        'description',
        'address_id',
        'phone',
        'email',
        'website',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'company_id', 'company_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'company_id', 'company_id');
    }

    public function locations()
    {
        return $this->hasMany(Location::class, 'company_id', 'company_id');
    }

    public function visitors()
    {
        return $this->hasMany(Visitor::class, 'company_id', 'company_id');
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'company_id', 'company_id');
    }

    public function companyUsers()
    {
        return $this->belongsToMany(User::class, 'company_user', 'company_id', 'user_id', 'company_id', 'id')
            ->withPivot('role_id', 'role_name', 'is_active');
    }
}
