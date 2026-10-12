<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Scopes\CompanyScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope());
    }

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'username',
        'email',
        'password',
        'phone',
        'role',
        'company_id',
        'department_id',
        'branch_id',
        'location_id',
        'status',
        'nationality',
        'age',
        'sex',
        'national_id',
        'position',
        'profile_photo',
        'approved_by',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_user', 'user_id', 'company_id', 'id', 'company_id')
            ->withPivot('role_id', 'role_name', 'is_active');
    }

    public function hasRole($role): bool
    {
        return strtolower($this->role) === strtolower($role);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function hasCompanyAccess($companyId): bool
    {
        if ($this->hasRole('system_admin')) {
            return true;
        }

        return $this->company_id == $companyId
            || $this->companies()->withoutGlobalScopes()->where('companies.company_id', $companyId)->exists();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function managedDepartment()
    {
        return $this->hasOne(Department::class, 'manager_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function visitRequestsAsHost()
    {
        return $this->hasMany(\App\Models\VisitRequest::class, 'host_id');
    }

    public function isCeo(): bool
    {
        return $this->role === 'ceo';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isHost(): bool
    {
        return $this->role === 'host';
    }

    public function isSecurityGuard(): bool
    {
        return $this->role === 'security_guard';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canReceiveVisitors(): bool
    {
        // Hosts can receive visitors if they are active and have the host role
        return $this->role === 'host' && $this->status === 'active';
    }
}
