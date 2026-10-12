<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_request_id',
        'previous_host_id',
        'new_host_id',
        'transferred_by',
        'company_id',
        'branch_id',
        'department_id',
        'reason',
    ];

    public function visitRequest(): BelongsTo
    {
        return $this->belongsTo(VisitRequest::class);
    }

    public function previousHost(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_host_id');
    }

    public function newHost(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_host_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
