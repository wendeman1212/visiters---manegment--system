<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsSync extends Model
{
    /** Give up retrying a message after this many attempts. */
    public const MAX_ATTEMPTS = 6;

    protected $table = 'sms_sync_queue';

    protected $fillable = [
        'visit_request_id',
        'recipient_type',
        'recipient_id',
        'phone',
        'event',
        'message',
        'status',
        'attempts',
        'reason',
        'last_attempt_at',
        'next_attempt_at',
        'company_id',
    ];

    protected $casts = [
        'visit_request_id' => 'integer',
        'recipient_id'     => 'integer',
        'company_id'       => 'integer',
        'attempts'         => 'integer',
        'last_attempt_at'  => 'datetime',
        'next_attempt_at'  => 'datetime',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }

    /**
     * Rows still eligible for (re)delivery:
     *  - never attempted (legacy/rows created before scheduling existed), or
     *  - a scheduled retry whose backoff window has elapsed.
     * Permanently failed rows (attempted, with no next_attempt_at) and
     * exhausted rows are deliberately excluded so the queue cannot loop.
     */
    public function scopeDue($query)
    {
        return $query
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($q) {
                $q->where(function ($legacy) {
                    $legacy->whereNull('next_attempt_at')->where('attempts', 0);
                })->orWhere(function ($scheduled) {
                    $scheduled->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', now());
                });
            });
    }
}
