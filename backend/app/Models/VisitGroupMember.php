<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitGroupMember extends Model
{
    protected $fillable = [
        'visit_request_id', 'name', 'id_document_no', 'email', 'phone',
        'gender', 'nationality', 'position',
        'qr_token', 'qr_expires_at', 'qr_used_at',
        'checked_in_at', 'checked_out_at',
        'exit_qr_token', 'exit_qr_expires_at',
        'badge_status',
    ];

    protected $casts = [
        'qr_expires_at'     => 'datetime',
        'qr_used_at'        => 'datetime',
        'checked_in_at'     => 'datetime',
        'checked_out_at'    => 'datetime',
        'exit_qr_expires_at'=> 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }

    public function badge()
    {
        return $this->hasOne(VisitorBadge::class, 'visit_group_member_id');
    }

    public function properties()
    {
        return $this->hasMany(VisitorProperty::class, 'visit_group_member_id');
    }

    public function checkInOutLogs()
    {
        return $this->hasMany(CheckInOutLog::class, 'visit_group_member_id');
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null && $this->checked_out_at === null;
    }

    public function isFullyCheckedOut(): bool
    {
        return $this->checked_out_at !== null;
    }
}
