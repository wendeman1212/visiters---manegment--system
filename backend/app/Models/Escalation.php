<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Escalation extends Model
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SLA    = 'sla';

    protected $fillable = [
        'visit_request_id',
        'escalated_by',
        'escalated_to',
        'reason',
        'trigger_type',
        'level',
        'sla_minutes',
        'deadline_at',
        'notified_at',
        'resolved_at',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'level'       => 'integer',
        'sla_minutes' => 'integer',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }

    public function escalatedBy()
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function escalatedTo()
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }
}
