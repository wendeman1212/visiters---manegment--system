<?php

namespace App\Services;

use App\Mail\VmsNotificationMail;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Per-request cache of the SMTP pre-flight result so multi-recipient
     * batches only probe the gateway once.
     */
    private static ?bool $smtpReachableCache = null;

    /**
     * Wall-clock deadline for the current request's deferred email phase. Set
     * by beginDeferredBudget() when the first terminating callback runs and
     * never cleared, so ALL deferred callbacks in the request share one
     * budget. sendEmailTo() abandons sends that would start after this
     * deadline. The static resets automatically at the end of each request.
     */
    private static ?float $deferredDeadline = null;

    /**
     * Open the deferred-delivery time budget for this request's terminating
     * phase. The first callback to run establishes the deadline; later
     * callbacks in the same request share it.
     */
    private static function beginDeferredBudget(): void
    {
        if (self::$deferredDeadline === null) {
            self::$deferredDeadline = microtime(true) + (float) config('mail.deferred_timeout', 4);
        }
    }

    /**
     * Create a SystemNotification and (optionally) deliver it by email and SMS.
     *
     * $smsToRecipient - when true, also send an SMS to the recipient's phone
     * (objects with a `phone` attribute) and record the sms_status in the
     * notification data payload alongside email_status.
     *
     * $companyId / $branchId - optionally constrain role-string recipients
     * ('Security Guard', 'Reception', 'admin', ...) to a specific company /
     * branch instead of every matching user across the whole system.
     */
    public static function send($title, $recipient, $message, $attachments = null, bool $smsToRecipient = false, $companyId = null, $branchId = null): SystemNotification
    {
        return self::dispatch($title, $recipient, $message, $attachments, $smsToRecipient, $companyId, $branchId, false);
    }

    /**
     * Same as send() but the email/SMS delivery is deferred until after the
     * HTTP response has been sent to the client (via Laravel's terminating
     * callbacks). The SystemNotification record is still created immediately so
     * it appears on dashboards right away — only the SMTP/SMS calls, which can
     * block for seconds when the gateway is slow, move off the request path.
     */
    public static function sendDeferred($title, $recipient, $message, $attachments = null, bool $smsToRecipient = false, $companyId = null, $branchId = null): SystemNotification
    {
        return self::dispatch($title, $recipient, $message, $attachments, $smsToRecipient, $companyId, $branchId, true);
    }

    /**
     * Send a notification restricted to an explicit set of delivery channels.
     *
     * $channels is any subset of: 'in_app' (a SystemNotification row is
     * created), 'email', 'sms'. Used by the SLA engine so the per-company
     * SLA notification toggles are honoured for warnings and automatic
     * escalations. Returns the SystemNotification row when an in-app record
     * was created, otherwise null (email/SMS may still have been queued).
     */
    public static function sendWithChannels(string $title, $recipient, string $message, array $channels, $attachments = null, bool $defer = true, $companyId = null, $branchId = null): ?SystemNotification
    {
        if (!$channels) {
            return null;
        }

        return self::dispatch($title, $recipient, $message, $attachments, false, $companyId, $branchId, $defer, array_values($channels));
    }

    private static function dispatch($title, $recipient, $message, $attachments, bool $smsToRecipient, $companyId, $branchId, bool $defer, ?array $channels = null): ?SystemNotification
    {
        $wantsInApp = $channels === null || in_array('in_app', $channels, true);
        $wantsEmail = $channels === null || in_array('email', $channels, true);
        $wantsSms   = $channels === null ? $smsToRecipient : in_array('sms', $channels, true);

        if (!$wantsInApp && !$wantsEmail && !$wantsSms) {
            return null;
        }

        $notif = new SystemNotification();

        if (is_object($recipient) && isset($recipient->id)) {
            // Visitors must be stored with recipient_type 'visitor' so their
            // dashboard query (SystemNotification::for('visitor', id)) finds it.
            $notif->recipient_type = $recipient instanceof \App\Models\Visitor ? 'visitor' : 'user';
            $notif->recipient_id = $recipient->id;
        } elseif (in_array($recipient, ['Security Guard', 'Security'])) {
            $notif->recipient_type = 'role';
            $notif->recipient_id = 'security_guard';
        } elseif ($recipient === 'Reception') {
            $notif->recipient_type = 'role';
            $notif->recipient_id = 'receptionist';
        } elseif (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $notif->recipient_type = 'email';
            $notif->recipient_id = $recipient;
        } elseif (is_string($recipient) && in_array($recipient, ['admin', 'ceo', 'host', 'security_guard', 'receptionist'])) {
            $notif->recipient_type = 'role';
            $notif->recipient_id = $recipient;
        } else {
            $notif->recipient_type = 'role';
            $notif->recipient_id = is_object($recipient) && isset($recipient->name) ? $recipient->name : (string) $recipient;
        }

        $notif->event = $title;
        $notif->title = $title;
        $notif->message = $message;

        $data = $attachments
            ? ['attachments' => $attachments]
            : [];
        if ($companyId) {
            $data['company_id'] = $companyId;
        }
        if ($branchId) {
            $data['branch_id'] = $branchId;
        }

        $deliver = static function () use ($notif, $recipient, $title, $message, $attachments, $companyId, $branchId, $wantsEmail, $wantsSms, &$data) {
            if ($wantsEmail) {
                $data['email_status'] = self::deliverEmail($recipient, $title, $message, $attachments, $companyId, $branchId);
            } else {
                $data['email_status'] = 'skipped';
            }
            if ($wantsSms) {
                $data['sms_status'] = self::deliverSmsToRecipient($recipient, $message);
            }
            if ($notif->exists) {
                $notif->forceFill(['data' => $data])->save();
            }
        };

        if (!$wantsInApp) {
            // In-app notifications are disabled for this send — only the
            // selected external channels are delivered, no record is stored.
            if ($defer) {
                app()->terminating(function () use ($deliver) {
                    self::beginDeferredBudget();
                    $deliver();
                });
            } else {
                $deliver();
            }

            return null;
        }

        if ($defer) {
            $data['email_status'] = $wantsEmail ? 'queued' : 'skipped';
            $notif->data = $data;
            $notif->save();
            app()->terminating(function () use ($deliver) {
                self::beginDeferredBudget();
                $deliver();
            });
        } else {
            $deliver();
            $notif->data = $data;
            $notif->save();
        }

        return $notif;
    }

    /**
     * Notify every active company admin and the company CEO about a visitor
     * event (e.g. a new visit request awaiting approval, or a check-in).
     * Each recipient gets an in-app notification (stored as a 'user'
     * notification so it appears on their dashboard) plus an email.
     */
    public static function sendToCompanyAdminsAndCeo(string $title, string $message, $companyId, $branchId = null): void
    {
        self::dispatchToCompanyLeaders($title, $message, $companyId, $branchId, false);
    }

    /**
     * Deferred variant of sendToCompanyAdminsAndCeo() — notification records
     * are created now, emails are delivered after the response is sent.
     */
    public static function sendToCompanyAdminsAndCeoDeferred(string $title, string $message, $companyId, $branchId = null): void
    {
        self::dispatchToCompanyLeaders($title, $message, $companyId, $branchId, true);
    }

    private static function dispatchToCompanyLeaders(string $title, string $message, $companyId, $branchId, bool $defer): void
    {
        if (!$companyId) {
            return;
        }

        $leaders = User::withoutGlobalScopes()
            ->whereIn('role', ['admin', 'ceo'])
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->get();

        foreach ($leaders as $leader) {
            if ($defer) {
                self::sendDeferred($title, $leader, $message);
            } else {
                self::send($title, $leader, $message);
            }
        }
    }

    public static function getAll($type, $id)
    {
        $query = SystemNotification::for($type, $id)->active();

        // Host transfers and escalations are an internal Admin/CEO/Host workflow.
        // The records stay in the database for the audit trail, but are never
        // surfaced on the visitor dashboard — only Host/Admin/CEO accounts keep
        // full transfer/escalation visibility.
        if ($type === 'visitor') {
            self::applyVisitorHiddenFilter($query);
        }

        return $query->orderBy('created_at', 'desc')->take(100)->get();
    }

    public static function getUnreadCount($type, $id)
    {
        $query = SystemNotification::for($type, $id)->active();

        if ($type === 'visitor') {
            self::applyVisitorHiddenFilter($query);
        }

        return $query->unread()->count();
    }

    /**
     * Restrict a visitor notification query to messages the visitor is allowed
     * to see. Transfer/escalation messages ("Host Changed", any message that
     * mentions an escalation) are hidden from visitors so the transfer never
     * surfaces on their dashboard, while Host/Admin/CEO recipients keep full
     * visibility.
     */
    private static function applyVisitorHiddenFilter($query): void
    {
        $query->where('title', '!=', 'Host Changed')
            ->whereRaw("LOWER(COALESCE(message, '')) NOT LIKE '%escalat%'");
    }

    /**
     * Strip internal escalation notes out of a visit's comments for the visitor
     * view. Only the copy handed to the visitor is scrubbed — the full comment
     * trail and the audit history in the database are untouched.
     */
    public static function sanitizeCommentsForVisitor(?string $comments): ?string
    {
        if ($comments === null || trim($comments) === '') {
            return $comments;
        }

        $kept = array_values(array_filter(
            preg_split('/\s*\|\s*/', $comments),
            fn ($part) => stripos($part, 'escalat') === false
        ));

        return $kept ? implode(' | ', $kept) : null;
    }

    public static function markAllRead($type, $id)
    {
        SystemNotification::for($type, $id)->unread()->update(['is_read' => true, 'read_at' => now()]);
    }

    public static function markRead($id)
    {
        SystemNotification::where('id', $id)->update(['is_read' => true, 'read_at' => now()]);
    }

    /**
     * Cancel a notification: mark it read, tag it with the 'cancelled' status
     * and stamp the cancellation time. It no longer appears in the bell list or
     * unread count, but the record stays in the database (notification history).
     */
    public static function cancel($type, $recipientId, $notificationId)
    {
        return SystemNotification::for($type, $recipientId)
            ->whereKey($notificationId)
            ->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'is_read'      => true,
                'read_at'      => now(),
            ]);
    }

    /**
     * Cancel all previously read (non-cancelled) notifications for a recipient.
     * Unread/new notifications are left untouched.
     */
    public static function cancelRead($type, $recipientId)
    {
        return SystemNotification::for($type, $recipientId)
            ->active()
            ->where('is_read', true)
            ->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);
    }

    public static function markUnread($id)
    {
        SystemNotification::where('id', $id)->update(['is_read' => false, 'read_at' => null]);
    }

    /**
     * Create a 'global' SystemNotification record for an emergency broadcast.
     *
     * When $companyId / $branchId are provided, the broadcast is only emailed
     * to that company's (and optionally branch's) CEOs and admins so it never
     * leaks to other companies/branches.
     */
    public static function emergencyAlert($title, $message, $companyId = null, $branchId = null)
    {
        $notif = new SystemNotification();
        $notif->recipient_type = 'global';
        $notif->recipient_id = 'all';
        $notif->event = $title;
        $notif->title = $title;
        $notif->message = $message;

        $emails = [];
        if ($companyId) {
            foreach (['ceo', 'admin'] as $role) {
                $roleEmails = self::usersByRole($role, $companyId, $branchId)->pluck('email')->filter()->all();
                if ($branchId && empty($roleEmails)) {
                    $roleEmails = self::usersByRole($role, $companyId)->pluck('email')->filter()->all();
                }
                $emails = array_merge($emails, $roleEmails);
            }
        }
        $emails = array_values(array_unique($emails));

        $notif->data = [
            'priority' => 'emergency', 'broadcast' => true,
            'email_status' => $emails ? 'queued' : 'no-recipient',
            'company_id' => $companyId, 'branch_id' => $branchId,
        ];
        $notif->save();

        app()->terminating(function () use ($notif, $emails, $title, $message) {
            self::beginDeferredBudget();
            $statuses = [];
            foreach ($emails as $email) {
                $statuses[] = self::sendEmailTo($email, $title, $message);
            }
            $emailStatus = $emails ? implode(',', array_unique($statuses)) : 'no-recipient';
            if ($notif->exists) {
                $notif->forceFill(['data' => array_merge($notif->data ?? [], ['email_status' => $emailStatus])])->save();
            }
        });

        return $notif;
    }

    /**
     * Deliver a broadcast to every active user in the given scope as a per-user
     * in-app notification (recipient_type 'user') so it appears in each matching
     * user's notification bell.
     *
     * Company scope (branch_id = null) reaches every active user in the company
     * across all branches; branch scope reaches ONLY users assigned to that
     * branch. Both company_id and branch_id are always part of the query, so a
     * branch-scoped broadcast can never reach users of other branches/companies.
     *
     * Returns the number of per-user notifications created.
     */
    public static function broadcastToScope(string $title, string $message, $companyId, $branchId = null, array $data = [], $excludeUserId = null): int
    {
        if (!$companyId) {
            return 0;
        }

        $users = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($excludeUserId, fn ($q) => $q->where('id', '!=', $excludeUserId))
            ->get(['id']);

        if ($users->isEmpty()) {
            return 0;
        }

        $now = now();
        $baseData = array_merge([
            'broadcast'  => true,
            'company_id' => $companyId,
            'branch_id'  => $branchId,
        ], $data);

        $rows = $users->map(fn (User $u) => [
            'event'          => $title,
            'recipient_type' => 'user',
            'recipient_id'   => $u->id,
            'title'          => $title,
            'message'        => $message,
            'data'           => json_encode($baseData),
            'is_read'        => false,
            'read_at'        => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->all();

        DB::table('system_notifications')->insert($rows);

        return count($rows);
    }

    public static function blacklistAlert($name, $docNo, $reason, $companyId = null, $branchId = null, bool $defer = false)
    {
        $notif = new SystemNotification();
        $notif->recipient_type = 'role';
        $notif->recipient_id = 'security_guard';
        $notif->event = 'Blacklist Alert';
        $notif->title = 'Blacklist Alert';
        $notif->message = "Blacklist match: Name={$name}, Document={$docNo}, Reason={$reason}";

        $emails = self::usersByRole('security_guard', $companyId, $branchId)->pluck('email')->filter()->all();

        if ($branchId && empty($emails)) {
            $emails = self::usersByRole('security_guard', $companyId)->pluck('email')->filter()->all();
        }

        $baseData = [
            'type' => 'blacklist', 'name' => $name, 'document_no' => $docNo, 'reason' => $reason,
            'company_id' => $companyId, 'branch_id' => $branchId,
        ];

        $deliver = static function () use ($emails, $notif, $baseData) {
            $statuses = [];
            foreach ($emails as $email) {
                $statuses[] = self::sendEmailTo($email, 'Blacklist Alert', $notif->message);
            }
            $emailStatus = $emails ? implode(',', array_unique($statuses)) : 'no-recipient';
            if ($notif->exists) {
                $notif->forceFill(['data' => array_merge($baseData, ['email_status' => $emailStatus])])->save();
            }
        };

        if ($defer) {
            $notif->data = array_merge($baseData, ['email_status' => 'queued']);
            $notif->save();
            app()->terminating(function () use ($deliver) {
                self::beginDeferredBudget();
                $deliver();
            });
        } else {
            $statuses = [];
            foreach ($emails as $email) {
                $statuses[] = self::sendEmailTo($email, 'Blacklist Alert', $notif->message);
            }
            $emailStatus = $emails ? implode(',', array_unique($statuses)) : 'no-recipient';
            $notif->data = array_merge($baseData, ['email_status' => $emailStatus]);
            $notif->save();
        }

        return $notif;
    }

    public static function sendToGroupMember($title, $member, $message)
    {
        return self::dispatchToGroupMember($title, $member, $message, false);
    }

    /**
     * Deferred variant of sendToGroupMember() — the notification record is
     * created immediately but the email is sent after the response.
     */
    public static function sendToGroupMemberDeferred($title, $member, $message)
    {
        return self::dispatchToGroupMember($title, $member, $message, true);
    }

    private static function dispatchToGroupMember($title, $member, $message, bool $defer)
    {
        $notif = new SystemNotification();
        $notif->recipient_type = 'group_member';
        $notif->recipient_id = $member->id ?? Str::random(8);
        $notif->event = $title;
        $notif->title = $title;
        $notif->message = $message;

        $email = $member->email ?? null;
        $name = $member->name ?? 'Group Member';

        $deliver = static function () use ($notif, $email, $title, $message, $name) {
            $emailStatus = $email
                ? self::sendEmailTo($email, $title, $message, null, $name)
                : 'no-recipient';
            if ($notif->exists) {
                $notif->forceFill(['data' => array_merge($notif->data ?? [], ['email_status' => $emailStatus])])->save();
            }
        };

        if ($defer) {
            $notif->data = ['group_member_id' => $member->id ?? null, 'email_status' => 'queued'];
            $notif->save();
            app()->terminating(function () use ($deliver) {
                self::beginDeferredBudget();
                $deliver();
            });
        } else {
            $emailStatus = $email
                ? self::sendEmailTo($email, $title, $message, null, $name)
                : 'no-recipient';
            $notif->data = ['group_member_id' => $member->id ?? null, 'email_status' => $emailStatus];
            $notif->save();
        }

        return $notif;
    }

    public function sendGroupMemberPasses(VisitRequest $visitRequest): void
    {
        foreach ($visitRequest->groupMembers as $member) {
            // send email/SMS with QR and pass attachment
            // also send to representative
        }
    }

    public function sendVisitorHostChanged($visitor, $visitRequest, $previousHost, $newHost)
    {
        try {
            if ($visitor->email) {
                Mail::to($visitor->email)->send(new \App\Mail\VisitorHostChangedMail($visitRequest, $previousHost, $newHost));
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send host change email: " . $e->getMessage());
        }

        if ($visitor->phone && method_exists($this, 'sendSms')) {
            try {
                $this->sendSms($visitor->phone, "Your host has been changed from {$previousHost->name} to {$newHost->name}.");
            } catch (\Throwable $e) {
                Log::warning("Failed to send host change SMS: " . $e->getMessage());
            }
        }
    }

    /**
     * Deliver an email notification using the default mailer (Gmail SMTP from .env).
     * The recipient may be a User/Visitor object, a VisitGroupMember, an email string,
     * or a role string. Failures are logged and reported as the return status.
     */
    private static function deliverEmail($recipient, string $title, string $message, $attachments = null, $companyId = null, $branchId = null): string
    {
        $emails = self::resolveRecipientEmails($recipient, $companyId, $branchId);

        if (empty($emails)) {
            return 'no-recipient';
        }

        $statuses = [];
        foreach ($emails as $email) {
            $statuses[] = self::sendEmailTo($email, $title, $message, $attachments);
        }

        return implode(',', array_unique($statuses));
    }

    /**
     * Send an SMS to a recipient that carries a phone attribute and return the
     * SmsService status so it can be stored as sms_status on the notification.
     */
    private static function deliverSmsToRecipient($recipient, string $message): string
    {
        if (is_object($recipient) && !empty($recipient->phone)) {
            $role = $recipient instanceof \App\Models\Visitor
                ? 'Visitor'
                : (isset($recipient->role) ? ucfirst($recipient->role) : 'Recipient');
            $greeting = 'Dear ' . ($recipient->name ?: $role) . " ({$role}), Phone: " . $recipient->phone . ". ";
            return SmsService::send($recipient->phone, $greeting . $message);
        }

        if (is_array($recipient) && !empty($recipient['phone'])) {
            $role = ucfirst($recipient['role'] ?? ($recipient['recipient_type'] ?? 'Recipient'));
            $greeting = 'Dear ' . ($recipient['name'] ?? $role) . " ({$role}), Phone: " . $recipient['phone'] . ". ";
            return SmsService::send($recipient['phone'], $greeting . $message);
        }

        return 'no-recipient';
    }

    /**
     * Resolve the email addresses a recipient string/object maps to.
     * Role strings ('Security Guard', 'Reception', 'admin', ...) are resolved
     * against users explicitly scoped to the given company / branch so that
     * broadcasts never leak across companies.
     */
    private static function resolveRecipientEmails($recipient, $companyId = null, $branchId = null): array
    {
        if (is_object($recipient)) {
            if (isset($recipient->email) && filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                return [$recipient->email];
            }
            return [];
        }

        if (is_string($recipient)) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                return [$recipient];
            }

            $role = match ($recipient) {
                'Security Guard', 'Security' => 'security_guard',
                'Reception' => 'receptionist',
                default => $recipient,
            };

            if (in_array($role, ['system_admin', 'ceo', 'admin', 'host', 'security_guard', 'receptionist'])) {
                $query = self::usersByRole($role, $companyId, $branchId);

                if ($branchId) {
                    $branchUsers = $query->pluck('email')->filter()->all();
                    if (!empty($branchUsers)) {
                        return $branchUsers;
                    }
                    // No branch-scoped recipients — fall back to the whole company.
                    $query = self::usersByRole($role, $companyId);
                }

                $emails = $query->pluck('email')->filter()->all();

                // Reception duties are handled by the company admins when no
                // receptionist accounts exist yet (legacy behaviour).
                if ($role === 'receptionist' && empty($emails)) {
                    $emails = self::usersByRole('admin', $companyId)->pluck('email')->filter()->all();
                }

                return $emails;
            }
        }

        return [];
    }

    /**
     * Base query for users holding a role, optionally restricted to a company
     * and/or branch. Uses withoutGlobalScopes so the result is deterministic
     * regardless of the currently authenticated user (or no auth context at
     * all, e.g. scheduled jobs).
     */
    private static function usersByRole(string $role, $companyId = null, $branchId = null)
    {
        $query = User::withoutGlobalScopes()
            ->where('role', $role)
            ->where('status', 'active');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /**
     * Fail-fast SMTP reachability probe. When the default mailer is SMTP but
     * the gateway is unreachable, Mail::send would block for the full
     * MAIL_TIMEOUT (10s+) per email, stalling deferred terminating callbacks.
     * This TCP pre-flight fails in ~1s instead. The result is cached for the
     * current request so multi-recipient batches only probe once.
     *
     * A records are resolved and probed directly rather than connecting by
     * hostname: on Windows, hostname resolution can prefer unreachable IPv6
     * (AAAA) or filtered addresses, making the connect slow/flaky, while a
     * direct IPv4 connect is fast and deterministic.
     */
    public static function smtpReachable(): bool
    {
        if (self::$smtpReachableCache !== null) {
            return self::$smtpReachableCache;
        }

        $default = config('mail.default');
        $transport = config("mail.mailers.{$default}.transport", $default);
        $host = config("mail.mailers.{$default}.host");
        $port = (int) config("mail.mailers.{$default}.port", 587);

        if ($transport !== 'smtp' || !$host || $port <= 0) {
            self::$smtpReachableCache = true;
            return true;
        }

        $targets = [];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $targets[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A) ?: [] as $rec) {
                if (!empty($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $targets[] = $rec['ip'];
                }
            }
            $targets[] = $host;
        }

        $errno = 0;
        $errstr = '';
        foreach ($targets as $target) {
            $fp = @stream_socket_client("tcp://{$target}:{$port}", $errno, $errstr, 1.0, STREAM_CLIENT_CONNECT);
            if ($fp) {
                fclose($fp);
                self::$smtpReachableCache = true;
                return true;
            }
        }

        Log::warning("SMTP pre-flight failed to {$host}:{$port} (errno {$errno}: {$errstr})");
        self::$smtpReachableCache = false;
        return false;
    }

    private static function sendEmailTo(string $email, string $title, string $message, $attachments = null, string $recipientName = ''): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid-email';
        }

        if (self::$deferredDeadline !== null && microtime(true) > self::$deferredDeadline) {
            Log::warning("Email notification '{$title}' deferred budget exceeded — queued for retry ({$email})");
            OutboxService::enqueue($email, $title, $message, $attachments, $recipientName);
            return 'queued';
        }

        try {
            if (!self::smtpReachable()) {
                // Offline / gateway unreachable: queue for a later retry rather
                // than dropping it. 'queued' is honest — it is NOT sent.
                Log::warning("Email notification '{$title}' queued: SMTP gateway unreachable ({$email})");
                OutboxService::enqueue($email, $title, $message, $attachments, $recipientName);
                return 'queued';
            }

            $data = $attachments ? ['attachments' => $attachments] : [];
            Mail::to($email)->send(new VmsNotificationMail(
                $title,
                $title,
                $message,
                $recipientName ?: 'Valued User',
                $data
            ));
            return 'sent';
        } catch (\Throwable $e) {
            Log::error("Email notification '{$title}' failed to {$email}, queued for retry: " . $e->getMessage());
            OutboxService::enqueue($email, $title, $message, $attachments, $recipientName);
            return 'queued';
        }
    }
}
