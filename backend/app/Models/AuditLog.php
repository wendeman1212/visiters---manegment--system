<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use App\Models\Scopes\CompanyScope;
use App\Models\Traits\CompanyScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use CompanyScoped;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_id',
        'actor_type',
        'company_id',
        'action',
        'entity',
        'entity_id',
        'before_value',
        'after_value',
        'ip_address',
        'user_agent',
        'branch_id',
    ];

    protected $casts = [
        'before_value' => 'array',
        'after_value' => 'array',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyScope());
        static::addGlobalScope(new BranchScope());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function actor()
    {
        if ($this->actor_type === 'user') {
            return $this->belongsTo(User::class, 'actor_id');
        } elseif ($this->actor_type === 'visitor') {
            return $this->belongsTo(Visitor::class, 'actor_id');
        }
        return null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
