<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use App\Models\Scopes\CompanyScope;
use App\Models\Traits\CompanyScoped;
use Illuminate\Database\Eloquent\Model;

class VisitRequest extends Model
{
    use CompanyScoped;
    protected $fillable = [
        'visitor_id', 'host_id', 'department_id', 'branch_id', 'location_id', 'visitor_category_id', 'purpose', 'requested_time', 'duration',
        'meeting_room_id', 'building', 'floor', 'status', 'priority', 'escalated_to',
        'approved_at', 'rejected_at', 'comments',
        'meeting_agenda', 'meeting_notes', 'meeting_outcome', 'visitor_notes',
        'is_vip', 'is_no_show', 'parking_space', 'wifi_credentials',
        'meeting_started_at', 'meeting_ended_at', 'no_show_at', 'alternate_host_id',
        'exit_qr_token', 'exit_qr_expires_at',
        'company_name', 'company_email', 'company_phone', 'company_address', 'company_website',
        'representative_gender', 'representative_nationality', 'representative_position',
        'visit_type',
        'company_id',
        'host_sla_started_at', 'host_sla_warned_at',
        'admin_sla_started_at', 'admin_sla_warned_at',
    ];

    protected $casts = [
        'requested_time'    => 'datetime',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'meeting_started_at'=> 'datetime',
        'meeting_ended_at'  => 'datetime',
        'no_show_at'        => 'datetime',
        'exit_qr_expires_at'=> 'datetime',
        'qr_deleted_at'     => 'datetime',
        'host_sla_started_at' => 'datetime',
        'host_sla_warned_at'  => 'datetime',
        'admin_sla_started_at'=> 'datetime',
        'admin_sla_warned_at' => 'datetime',
        'is_vip'            => 'boolean',
        'is_no_show'        => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new BranchScope());
        static::addGlobalScope(new CompanyScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    /**
     * Resolve the actual visitor/representative for this visit even when the linked
     * Visitor account belongs to a different company than the visit (the global
     * CompanyScope would hide it) or when only company contact details were provided.
     */
    public function resolveVisitor()
    {
        if ($this->visitor_id) {
            // Prefer the eager-loaded relation when present (avoids an N+1 query
            // for every visit row on dashboards); only fall back to an unscoped
            // lookup when the relation is missing/empty (e.g. visitor belongs to
            // a different company and was hidden by the global scope).
            $visitor = $this->relationLoaded('visitor') ? $this->visitor : null;
            if (!$visitor) {
                $visitor = Visitor::withoutGlobalScopes()->where('id', $this->visitor_id)->first();
            }
            if ($visitor) {
                return $visitor;
            }
        }

        if ($this->visit_type === 'company' && (!empty($this->company_email) || !empty($this->company_phone))) {
            return Visitor::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->where(function ($q) {
                    if (!empty($this->company_email)) {
                        $q->where('email', $this->company_email);
                    }
                    if (!empty($this->company_phone)) {
                        $q->orWhere('phone', $this->company_phone);
                    }
                })->first();
        }

        return null;
    }

    public function isEscalated()
    {
        return $this->status === 'escalated';
    }

    public function isPendingOrEscalated()
    {
        return in_array($this->status, ['pending', 'escalated']);
    }

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeForBranch($query, $branchId)
    {
        if ($branchId) {
            return $query->where('branch_id', $branchId);
        }
        return $query;
    }

    public function scopeForLocation($query, $locationId)
    {
        if ($locationId) {
            return $query->where('location_id', $locationId);
        }
        return $query;
    }

    public function category()
    {
        return $this->belongsTo(VisitorCategory::class, 'visitor_category_id');
    }

    public function escalatedTo()
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    public function alternateHost()
    {
        return $this->belongsTo(User::class, 'alternate_host_id');
    }

    public function qrToken()
    {
        return $this->hasOne(QRToken::class, 'visit_request_id');
    }

    public function checkInOutLogs()
    {
        return $this->hasMany(CheckInOutLog::class, 'visit_request_id');
    }

    public function escalations()
    {
        return $this->hasMany(Escalation::class, 'visit_request_id');
    }

    public function feedback()
    {
        return $this->hasOne(Feedback::class, 'visit_request_id');
    }

    public function properties()
    {
        return $this->hasMany(VisitorProperty::class, 'visit_request_id');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'related_visit_id');
    }

    public function badge()
    {
        return $this->hasOne(VisitorBadge::class, 'visit_request_id');
    }

    public function groupMembers()
    {
        return $this->hasMany(VisitGroupMember::class, 'visit_request_id');
    }

    public function documents()
    {
        return $this->hasMany(VisitDocument::class, 'visit_request_id');
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(RescheduleRequest::class, 'visit_request_id');
    }
}
