<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPolicy extends Model
{
    public const DEFAULT_SLA_MINUTES = 5;

    protected $fillable = [
        'company_id',
        'sla_minutes',
        'admin_sla_minutes',
        'auto_escalation_enabled',
        'sla_warning_minutes',
        'sla_pause_off_hours',
        'sla_count_weekends',
        'sla_count_holidays',
        'sla_notify_in_app',
        'sla_notify_email',
        'sla_notify_sms',
    ];

    protected $casts = [
        'sla_minutes'            => 'integer',
        'admin_sla_minutes'      => 'integer',
        'auto_escalation_enabled'=> 'boolean',
        'sla_warning_minutes'    => 'integer',
        'sla_pause_off_hours'    => 'boolean',
        'sla_count_weekends'     => 'boolean',
        'sla_count_holidays'     => 'boolean',
        'sla_notify_in_app'      => 'boolean',
        'sla_notify_email'       => 'boolean',
        'sla_notify_sms'         => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public static function slaMinutesForCompany(?int $companyId): int
    {
        return static::forCompany($companyId)->hostSlaMinutes();
    }

    /**
     * Effective policy for a company. Always returns a model — a company
     * without a stored row gets a fresh default instance, so callers never
     * have to null-check.
     */
    public static function forCompany(?int $companyId): self
    {
        if (!$companyId) {
            return new self(['company_id' => null] + static::defaults());
        }

        $policy = static::where('company_id', $companyId)->first();

        return $policy ?: new self(['company_id' => $companyId] + static::defaults());
    }

    /** Defaults applied to a brand-new (unsaved) policy instance. */
    public static function defaults(): array
    {
        return [
            'sla_minutes'             => self::DEFAULT_SLA_MINUTES,
            'admin_sla_minutes'       => null,
            'auto_escalation_enabled' => true,
            'sla_warning_minutes'     => 5,
            'sla_pause_off_hours'     => false,
            'sla_count_weekends'      => true,
            'sla_count_holidays'      => true,
            'sla_notify_in_app'       => true,
            'sla_notify_email'        => true,
            'sla_notify_sms'          => false,
        ];
    }

    /** Level 1 — host SLA in minutes. */
    public function hostSlaMinutes(): int
    {
        return max(1, (int) ($this->sla_minutes ?? self::DEFAULT_SLA_MINUTES));
    }

    /** Level 2 — admin SLA in minutes (falls back to the host SLA). */
    public function adminSlaMinutes(): int
    {
        $minutes = $this->admin_sla_minutes !== null
            ? (int) $this->admin_sla_minutes
            : $this->hostSlaMinutes();

        return max(1, $minutes);
    }

    /** Delivery channels enabled for SLA warnings / auto-escalations. */
    public function notifyChannels(): array
    {
        $channels = [];
        if ($this->sla_notify_in_app !== false) $channels[] = 'in_app';
        if ($this->sla_notify_email !== false)  $channels[] = 'email';
        if ($this->sla_notify_sms === true)     $channels[] = 'sms';

        return $channels ?: ['in_app'];
    }

    /** Full payload for the policy settings forms (no hard-coded UI values). */
    public function toSettingsArray(): array
    {
        return [
            'sla_minutes'             => $this->hostSlaMinutes(),
            'admin_sla_minutes'       => $this->admin_sla_minutes,
            'auto_escalation_enabled' => (bool) $this->auto_escalation_enabled,
            'sla_warning_minutes'     => (int) $this->sla_warning_minutes,
            'sla_pause_off_hours'     => (bool) $this->sla_pause_off_hours,
            'sla_count_weekends'      => (bool) $this->sla_count_weekends,
            'sla_count_holidays'      => (bool) $this->sla_count_holidays,
            'sla_notify_in_app'       => (bool) $this->sla_notify_in_app,
            'sla_notify_email'        => (bool) $this->sla_notify_email,
            'sla_notify_sms'          => (bool) $this->sla_notify_sms,
        ];
    }
}
