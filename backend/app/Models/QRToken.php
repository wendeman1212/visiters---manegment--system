<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QRToken extends Model
{
    protected $table = 'qr_tokens';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'visit_request_id', 'visit_group_member_id',
        'token_value', 'type', 'expires_at', 'used_at',
        'model_type', 'model_id', 'status', 'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }

    public function visitGroupMember()
    {
        return $this->belongsTo(VisitGroupMember::class);
    }

    public function scopeToken($query, $token)
    {
        return $query->where('token_value', $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null || $this->status === self::STATUS_REVOKED;
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null || $this->status === self::STATUS_USED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast()
            || $this->status === self::STATUS_EXPIRED;
    }
}
