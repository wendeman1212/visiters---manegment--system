<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostChangeRequest extends Model
{
    protected $fillable = [
        'visit_request_id', 'previous_host_id', 'new_host_id', 'new_department_id',
        'requested_by', 'requested_by_role', 'reason', 'additional_notes',
        'meeting_room', 'previous_location', 'new_location',
        'status', 'reviewed_by', 'review_notes', 'reviewed_at', 'transferred_at',
    ];

    protected $casts = [
        'reviewed_at'    => 'datetime',
        'transferred_at' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }

    public function previousHost()
    {
        return $this->belongsTo(User::class, 'previous_host_id');
    }

    public function newHost()
    {
        return $this->belongsTo(User::class, 'new_host_id');
    }

    public function newDepartment()
    {
        return $this->belongsTo(Department::class, 'new_department_id');
    }

    public function requestedByUser()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
