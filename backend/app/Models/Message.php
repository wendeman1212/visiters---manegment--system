<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'subject', 'body',
        'sender_type', 'sender_id',
        'recipient_type', 'recipient_id',
        'parent_id',
        'is_read', 'read_at',
        'sender_deleted', 'recipient_deleted',
        'email_sent', 'sms_sent',
    ];

    protected $casts = [
        'is_read'         => 'boolean',
        'sender_deleted'  => 'boolean',
        'recipient_deleted' => 'boolean',
        'email_sent'      => 'boolean',
        'sms_sent'        => 'boolean',
    ];

    public function sender()
    {
        return $this->morphTo();
    }

    public function recipient()
    {
        return $this->morphTo();
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    public function scopeInbox($query, string $recipientType, int $recipientId)
    {
        return $query->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('recipient_deleted', false)
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc');
    }

    public function scopeSent($query, string $senderType, int $senderId)
    {
        return $query->where('sender_type', $senderType)
            ->where('sender_id', $senderId)
            ->where('sender_deleted', false)
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc');
    }

    public function scopeUnreadCount($query, string $recipientType, int $recipientId): int
    {
        return $query->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('is_read', false)
            ->where('recipient_deleted', false)
            ->count();
    }

    public function getParticipantName(): string
    {
        if ($this->sender) {
            return $this->sender->name ?? 'Unknown';
        }
        return 'Unknown';
    }

    public function getRecipientName(): string
    {
        if ($this->recipient) {
            return $this->recipient->name ?? 'Unknown';
        }
        return 'Unknown';
    }
}
