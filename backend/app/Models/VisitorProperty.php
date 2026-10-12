<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorProperty extends Model
{
    protected $fillable = [
        'visit_request_id',
        'visitor_id',
        'visit_group_member_id',
        'item_name',
        'quantity',
        'serial_number',
        'photo_url',
        'property_tag',
        'status',
        'checked_in_at',
        'returned_at',
        'registered_by',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'returned_at'   => 'datetime',
        'quantity'       => 'integer',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }

    /**
     * Owning visitor account (representative / individual). Group-member-owned
     * properties have this null and rely on the visit_group_member_id instead.
     */
    public function ownerVisitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    public function groupMember()
    {
        return $this->belongsTo(VisitGroupMember::class, 'visit_group_member_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Scope a property query to a single owner — the rule is strictly
     * 1 person = 1 property = 1 owner.
     *
     * Pass the owning group member's id to scope to that member, or the owning
     * visitor's id to scope to the representative / individual visitor. Ownership
     * is always derived from the current person's unique id, never from
     * visit_id / representative-id inference alone.
     *
     * Legacy rows created before explicit ownership tracking stored the
     * representative's items as visit-scoped rows without a visitor_id or a
     * group_member_id; they are matched as a backward-compatible fallback.
     */
    public function scopeForExit($query, $visitorId = null, $groupMemberId = null)
    {
        if ($groupMemberId) {
            return $query->where('visit_group_member_id', $groupMemberId);
        }

        if ($visitorId) {
            return $query->where(function ($q) use ($visitorId) {
                $q->where('visitor_id', $visitorId)
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('visitor_id')->whereNull('visit_group_member_id');
                    });
            });
        }

        return $query->whereNull('visit_group_member_id');
    }
}
