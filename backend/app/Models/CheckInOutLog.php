<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckInOutLog extends Model
{
    protected $table = 'check_in_out_logs';

    protected $fillable = [
        'visit_request_id',
        'visit_group_member_id',
        'checkin_time',
        'checkout_time',
        'guard_id',
    ];

    protected $casts = [
        'checkin_time' => 'datetime',
        'checkout_time' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }

    public function groupMember()
    {
        return $this->belongsTo(VisitGroupMember::class, 'visit_group_member_id');
    }

    public function guardUser()
    {
        return $this->belongsTo(User::class, 'guard_id');
    }
}
