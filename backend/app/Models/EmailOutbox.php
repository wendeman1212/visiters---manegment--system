<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOutbox extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    /** Give up after this many delivery attempts. */
    public const MAX_ATTEMPTS = 8;

    protected $table = 'email_outbox';

    protected $fillable = [
        'to_email',
        'to_name',
        'subject',
        'body',
        'attachments',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'sent_at',
    ];

    protected $casts = [
        'attachments'     => 'array',
        'attempts'        => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at'         => 'datetime',
    ];

    /**
     * Rows still eligible for delivery: never attempted (legacy), or a scheduled
     * retry whose backoff window has elapsed. Exhausted / permanently failed
     * rows are excluded.
     */
    public function scopeDue($query)
    {
        return $query
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_FAILED])
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
