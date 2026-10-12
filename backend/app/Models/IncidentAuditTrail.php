<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAuditTrail extends Model
{
    protected $table = 'incident_audit_trail';

    protected $fillable = [
        'incident_id', 'actor_type', 'actor_id',
        'action', 'from_status', 'to_status',
        'notes', 'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
