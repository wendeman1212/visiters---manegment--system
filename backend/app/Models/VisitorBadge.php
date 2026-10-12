<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorBadge extends Model
{
    protected $fillable = [
        'visit_request_id', 'visit_group_member_id', 'file_path',
        'visitor_name', 'visitor_id_document', 'visitor_photo_url',
        'host_name', 'department', 'purpose', 'scheduled_time', 'duration',
        'priority', 'is_vip', 'qr_token', 'badge_color', 'issued_at',
    ];

    protected $casts = [
        'is_vip'    => 'boolean',
        'issued_at' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }

    public function visitGroupMember()
    {
        return $this->belongsTo(VisitGroupMember::class);
    }
}
