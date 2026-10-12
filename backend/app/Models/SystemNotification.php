<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemNotification extends Model
{
    protected $fillable = [
        'event', 'recipient_type', 'recipient_id', 'title', 'message',
        'data', 'is_read', 'read_at', 'status', 'cancelled_at',
    ];

    protected $casts = [
        'data'         => 'array',
        'is_read'      => 'boolean',
        'read_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function scopeFor($query, $type, $id)
    {
        return $query->where('recipient_type', $type)->where('recipient_id', $id);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
