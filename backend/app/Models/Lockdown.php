<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lockdown extends Model
{
    protected $table = 'security_lockdowns';

    protected $fillable = [
        'company_id',
        'branch_id',
        'scope_type',
        'reason',
        'started_by',
        'started_at',
        'ended_by',
        'ended_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * Whether the given company/branch is under an active lockdown.
     *
     * A company-wide lockdown (branch_id null) covers every branch of that
     * company; a branch lockdown only covers its own branch.
     */
    public static function isLocked(?int $companyId, ?int $branchId = null): bool
    {
        return self::activeFor($companyId, $branchId) !== null;
    }

    /**
     * The active lockdown covering the given company/branch, if any.
     */
    public static function activeFor(?int $companyId, ?int $branchId = null): ?Lockdown
    {
        if (!$companyId) {
            return null;
        }

        return static::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->latest('started_at')
            ->first();
    }

    /**
     * The currently active lockdown that was opened at a specific scope
     * (used to lift a branch-level lockdown without touching company-level).
     */
    public static function activeById(int $id): ?Lockdown
    {
        return static::query()->where('id', $id)->where('status', 'active')->first();
    }

    /**
     * End this lockdown, recording who lifted it and when.
     */
    public function end(?int $endedBy = null): bool
    {
        return $this->update([
            'status'   => 'ended',
            'ended_by' => $endedBy,
            'ended_at' => now(),
        ]);
    }
}
