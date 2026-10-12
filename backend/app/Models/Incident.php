<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    public const TYPE_FAKE_QR = 'fake_qr';
    public const TYPE_INVALID_QR = 'invalid_qr';
    public const TYPE_EXPIRED_QR = 'expired_qr';
    public const TYPE_REVOKED_QR = 'revoked_qr';
    public const TYPE_USED_QR = 'used_qr';
    public const TYPE_UNAUTHORIZED_ENTRY = 'unauthorized_entry';
    public const TYPE_UNAUTHORIZED_EXIT = 'unauthorized_exit';
    public const TYPE_BLACKLIST_MATCH = 'blacklist_match';
    public const TYPE_IDENTITY_MISMATCH = 'identity_mismatch';
    public const TYPE_MISSING_PROPERTY = 'missing_property';
    public const TYPE_DAMAGED_PROPERTY = 'damaged_property';
    public const TYPE_PROPERTY_MISMATCH = 'property_owner_mismatch';
    public const TYPE_LOST_PROPERTY = 'lost_property';
    public const TYPE_SECURITY_ALERT = 'security_alert';
    public const TYPE_PANIC_ALARM = 'panic_alarm';
    public const TYPE_EMERGENCY = 'emergency';
    public const TYPE_OTHER = 'other';

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const RESOLUTION_REASON_RETURNED = 'returned';
    public const RESOLUTION_REASON_REPLACED = 'replaced';
    public const RESOLUTION_REASON_RECOVERED = 'recovered';
    public const RESOLUTION_REASON_ACCEPTED = 'accepted';
    public const RESOLUTION_REASON_CLOSED = 'closed';
    public const RESOLUTION_REASON_FALSE_POSITIVE = 'false_positive';
    public const RESOLUTION_REASON_OTHER = 'other';

    public const ALLOWED_INVESTIGATORS = [
        'super_admin', 'admin', 'security_manager', 'security_guard', 'manager',
    ];

    public const ALLOWED_RESOLVERS = [
        'super_admin', 'admin', 'security_manager',
    ];

    public const ALLOWED_DISMISSERS = [
        'super_admin', 'admin', 'security_manager', 'security_guard',
    ];

    protected $fillable = [
        'type', 'severity', 'status',
        'title', 'description',
        'related_visit_id', 'related_visitor_id', 'related_group_member_id',
        'scan_token', 'checkpoint_id',
        'reported_by', 'action_taken',
        'resolved_at', 'resolved_by',
        'resolution_reason', 'resolution_action', 'resolution_notes',
        'evidence', 'attempt_count', 'last_attempt_at',
        'branch_id', 'company_id', 'location_id',
    ];

    protected $casts = [
        'resolved_at'    => 'datetime',
        'last_attempt_at'=> 'datetime',
        'evidence'       => 'array',
        'attempt_count'  => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new BranchScope());
        static::addGlobalScope(new CompanyScope());
    }

    public function visitRequest(): BelongsTo
    {
        return $this->belongsTo(VisitRequest::class, 'related_visit_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'related_visitor_id');
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(VisitGroupMember::class, 'related_group_member_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'checkpoint_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function auditTrail(): HasMany
    {
        return $this->hasMany(IncidentAuditTrail::class, 'incident_id')
            ->latest('created_at');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_FAKE_QR            => 'Fake / Invalid QR Code',
            self::TYPE_INVALID_QR         => 'Invalid QR Code',
            self::TYPE_EXPIRED_QR         => 'Expired QR Code',
            self::TYPE_REVOKED_QR         => 'Revoked QR Code',
            self::TYPE_USED_QR            => 'Already-Used QR Code',
            self::TYPE_UNAUTHORIZED_ENTRY => 'Unauthorized Entry',
            self::TYPE_UNAUTHORIZED_EXIT  => 'Unauthorized Exit',
            self::TYPE_BLACKLIST_MATCH    => 'Blacklist Match',
            self::TYPE_IDENTITY_MISMATCH  => 'Identity Mismatch',
            self::TYPE_MISSING_PROPERTY   => 'Missing Property',
            self::TYPE_DAMAGED_PROPERTY   => 'Damaged Property',
            self::TYPE_PROPERTY_MISMATCH  => 'Property Owner Mismatch',
            self::TYPE_LOST_PROPERTY      => 'Lost Property',
            self::TYPE_SECURITY_ALERT     => 'Security Alert',
            self::TYPE_PANIC_ALARM        => 'Panic Alarm',
            self::TYPE_EMERGENCY          => 'Emergency',
            self::TYPE_OTHER              => 'Other',
            default                       => ucwords(str_replace('_', ' ', $type)),
        };
    }

    public function displayTypeLabel(): string
    {
        return self::typeLabel($this->type);
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_LOW      => 'Low',
            self::SEVERITY_MEDIUM   => 'Medium',
            self::SEVERITY_HIGH     => 'High',
            self::SEVERITY_CRITICAL => 'Critical',
            default                 => ucfirst($severity),
        };
    }

    public function displaySeverityLabel(): string
    {
        return self::severityLabel($this->severity);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN          => 'Open',
            self::STATUS_INVESTIGATING => 'Investigating',
            self::STATUS_RESOLVED      => 'Resolved',
            self::STATUS_DISMISSED     => 'Dismissed',
            default                    => ucfirst($status),
        };
    }

    public function displayStatusLabel(): string
    {
        return self::statusLabel($this->status);
    }

    public static function resolutionReasonLabel(string $reason): string
    {
        return match ($reason) {
            self::RESOLUTION_REASON_RETURNED        => 'Returned',
            self::RESOLUTION_REASON_REPLACED        => 'Replaced',
            self::RESOLUTION_REASON_RECOVERED       => 'Recovered',
            self::RESOLUTION_REASON_ACCEPTED       => 'Accepted',
            self::RESOLUTION_REASON_CLOSED          => 'Closed',
            self::RESOLUTION_REASON_FALSE_POSITIVE  => 'False Positive',
            self::RESOLUTION_REASON_OTHER           => 'Other',
            default                                 => ucfirst($reason),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            self::SEVERITY_LOW      => '#10b981',
            self::SEVERITY_MEDIUM   => '#f59e0b',
            self::SEVERITY_HIGH     => '#f97316',
            self::SEVERITY_CRITICAL => '#ef4444',
            default                 => '#6b7280',
        };
    }
}
