<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RescheduleRequest extends Model
{
    protected $fillable = [
        'visit_request_id', 'requested_by', 'requested_by_visitor', 'requested_by_role',
        'old_requested_time', 'old_duration', 'new_requested_time', 'new_duration',
        'reason', 'status', 'reviewer_comments', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'old_requested_time' => 'datetime',
        'new_requested_time' => 'datetime',
        'reviewed_at'        => 'datetime',
    ];

    public function visitRequest() { return $this->belongsTo(VisitRequest::class); }
    public function requestedByUser() { return $this->belongsTo(User::class, 'requested_by'); }
    public function requestedByVisitor() { return $this->belongsTo(Visitor::class, 'requested_by_visitor'); }
    public function reviewedBy() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
