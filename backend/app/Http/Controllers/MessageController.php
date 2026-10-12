<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\VmsNotificationMail;
use Inertia\Inertia;

class MessageController extends Controller
{
    private function getSender()
    {
        $webUser = Auth::guard('web')->user();
        $visitorUser = Auth::guard('visitor')->user();

        if ($webUser) {
            return ['type' => 'user', 'model' => $webUser];
        }
        if ($visitorUser) {
            return ['type' => 'visitor', 'model' => $visitorUser];
        }
        return null;
    }

    private function getInput(Request $request)
    {
        if ($request->isJson() || $request->header('Content-Type') === 'application/json') {
            $json = $request->json()->all();
            if (!empty($json)) {
                return $json;
            }
        }
        return $request->all();
    }

    /**
     * Sender identity line used inside outgoing SMS bodies so the recipient
     * always sees who wrote (name), their role and their phone number.
     */
    private function smsSenderLabel(array $sender): string
    {
        $name = $sender['model']->name ?? 'Unknown';
        $role = $sender['type'] === 'visitor'
            ? 'Visitor'
            : ucfirst($sender['model']->role ?? 'User');
        $phone = $sender['model']->phone ?: 'N/A';

        return "{$name} ({$role}), Phone: {$phone}";
    }

    public function index(Request $request)
    {
        $sender = $this->getSender();
        if (!$sender) abort(403);

        $folder = $request->input('folder', 'inbox');
        $search = $request->input('search', '');

        $recipients = self::recipientsFor($sender['type'], $sender['model']);

        $inboxQuery = Message::inbox($sender['type'], $sender['model']->id);
        if ($search) {
            $inboxQuery->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }
        $inboxMessages = $inboxQuery->with('sender')->withCount('replies')->get()->map(function ($msg) {
            return [
                'id'           => (int)$msg->id,
                'subject'      => $msg->subject ?: '(No subject)',
                'body'         => $msg->body,
                'sender_name'  => $msg->sender->name ?? 'Unknown',
                'sender_phone' => $msg->sender->phone ?? null,
                'sender_email' => $msg->sender->email ?? null,
                'sender_type'  => $msg->sender_type,
                'sender_id'    => (int)$msg->sender_id,
                'created_at'   => $msg->created_at->format('M d, Y H:i'),
                'is_read'      => $msg->is_read,
                'reply_count'  => (int)$msg->replies_count,
            ];
        });

        $sentQuery = Message::sent($sender['type'], $sender['model']->id);
        if ($search) {
            $sentQuery->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }
        $sentMessages = $sentQuery->with('recipient')->withCount('replies')->get()->map(function ($msg) {
            return [
                'id'              => (int)$msg->id,
                'subject'         => $msg->subject ?: '(No subject)',
                'body'            => $msg->body,
                'recipient_name'  => $msg->recipient->name ?? 'Unknown',
                'recipient_phone' => $msg->recipient->phone ?? null,
                'recipient_email' => $msg->recipient->email ?? null,
                'recipient_type'  => $msg->recipient_type,
                'recipient_id'    => (int)$msg->recipient_id,
                'created_at'      => $msg->created_at->format('M d, Y H:i'),
                'is_read'         => $msg->is_read,
                'reply_count'     => (int)$msg->replies_count,
            ];
        });

        return response()->json([
            'inbox'        => $inboxMessages,
            'sent'         => $sentMessages,
            'recipients'   => $recipients,
            'unread_count' => (int)Message::unreadCount($sender['type'], $sender['model']->id),
        ]);
    }

    public function thread($id)
    {
        $sender = $this->getSender();
        if (!$sender) abort(403);

        $message = Message::with('sender', 'recipient', 'replies.sender', 'replies.recipient')
            ->where(function ($q) use ($sender) {
                $q->where(function ($q2) use ($sender) {
                    $q2->where('recipient_type', $sender['type'])
                       ->where('recipient_id', $sender['model']->id)
                       ->where('recipient_deleted', false);
                })->orWhere(function ($q2) use ($sender) {
                    $q2->where('sender_type', $sender['type'])
                       ->where('sender_id', $sender['model']->id)
                       ->where('sender_deleted', false);
                });
            })
            ->whereNull('parent_id')
            ->findOrFail($id);

        if ($message->recipient_type === $sender['type'] && $message->recipient_id === $sender['model']->id && !$message->is_read) {
            $message->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json([
            'id'              => (int)$message->id,
            'subject'         => $message->subject ?: '(No subject)',
            'body'            => $message->body,
            'sender_name'     => $message->sender->name ?? 'Unknown',
            'sender_phone'    => $message->sender->phone ?? null,
            'sender_email'    => $message->sender->email ?? null,
            'sender_type'     => $message->sender_type,
            'sender_id'       => (int)$message->sender_id,
            'recipient_name'  => $message->recipient->name ?? 'Unknown',
            'recipient_phone' => $message->recipient->phone ?? null,
            'recipient_email' => $message->recipient->email ?? null,
            'recipient_type'  => $message->recipient_type,
            'recipient_id'    => (int)$message->recipient_id,
            'created_at'      => $message->created_at->format('M d, Y H:i'),
            'is_read'         => $message->is_read,
            'replies'         => $message->replies->map(fn($r) => [
                'id'          => (int)$r->id,
                'body'        => $r->body,
                'sender_name' => $r->sender->name ?? 'Unknown',
                'sender_phone'=> $r->sender->phone ?? null,
                'sender_email'=> $r->sender->email ?? null,
                'sender_type' => $r->sender_type,
                'sender_id'   => (int)$r->sender_id,
                'created_at'  => $r->created_at->format('M d, Y H:i'),
            ]),
        ]);
    }

    public function send(Request $request)
    {
        $sender = $this->getSender();
        if (!$sender) return response()->json(['error' => 'Unauthorized'], 403);

        $input = $this->getInput($request);

        $validator = \Illuminate\Support\Facades\Validator::make($input, [
            'recipient_type' => 'required|in:user,visitor',
            'recipient_id'   => 'required|integer|min:1',
            'subject'        => 'nullable|string|max:190',
            'body'           => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $recipientType = $input['recipient_type'] ?? null;
        $recipientId   = isset($input['recipient_id']) ? (int)$input['recipient_id'] : null;
        $subject       = $input['subject'] ?? null;
        $body          = $input['body'] ?? null;

        if (!$recipientType || !in_array($recipientType, ['user', 'visitor'])) {
            return response()->json(['errors' => ['recipient_type' => ['The selected recipient type is invalid.']]], 422);
        }
        if (!$recipientId || $recipientId <= 0) {
            return response()->json(['errors' => ['recipient_id' => ['The recipient id field is required.']]], 422);
        }
        if (!$body || trim($body) === '') {
            return response()->json(['errors' => ['body' => ['The body field is required.']]], 422);
        }

        $recipientModel = $recipientType === 'user'
            ? User::find($recipientId)
            : Visitor::find($recipientId);

        if (!$recipientModel) {
            return response()->json(['errors' => ['recipient_id' => ['Recipient not found.']]], 422);
        }

        if ($sender['type'] === $recipientType && $sender['model']->id == $recipientId) {
            return response()->json(['errors' => ['recipient_id' => ['You cannot send a message to yourself.']]], 422);
        }

        if (!self::recipientInScope($sender['type'], $sender['model'], $recipientType, $recipientModel)) {
            return response()->json(['errors' => ['recipient_id' => ['Recipient is not in your company/branch.']]], 422);
        }

        $message = Message::create([
            'subject'        => $subject,
            'body'           => $body,
            'sender_type'    => $sender['type'],
            'sender_id'      => $sender['model']->id,
            'recipient_type' => $recipientType,
            'recipient_id'   => $recipientId,
        ]);

        AuditLogger::log('message_sent', 'messages', $message->id, null, [
            'from'    => $sender['model']->name,
            'to'      => $recipientModel->name,
            'subject' => $subject,
        ]);

        // Email + SMS can block for seconds when SMTP/Twilio is slow. Send them
        // after the response is flushed so the API returns immediately; the
        // delivery flags are stamped when the deferred work finishes.
        $senderName = $sender['model']->name;
        app()->terminating(function () use ($recipientModel, $message, $senderName, $subject, $body, $sender) {
            $emailSent = false;
            try {
                if ($recipientModel->email) {
                    $mailSubject = $subject ?: 'New Message from ' . $senderName;
                    Mail::to($recipientModel->email)->send(new VmsNotificationMail(
                        'New Message', $mailSubject, $body, $recipientModel->name,
                        ['from_user' => $senderName]
                    ));
                    $emailSent = true;
                }
            } catch (\Exception $e) {
                Log::error("Message email failed: " . $e->getMessage());
            }

            $smsSent = false;
            try {
                if ($recipientModel->phone) {
                    $smsBody = "[VMS] From " . $this->smsSenderLabel($sender) . ": " . \Illuminate\Support\Str::limit($body, 160);
                    $smsSent = SmsService::send($recipientModel->phone, $smsBody) === 'sent';
                }
            } catch (\Exception $e) {
                Log::error("Message SMS failed: " . $e->getMessage());
            }

            $message->update(['email_sent' => $emailSent, 'sms_sent' => $smsSent]);
        });

        return response()->json(['success' => true, 'message_id' => $message->id]);
    }

    public function reply(Request $request, $parentId)
    {
        $sender = $this->getSender();
        if (!$sender) return response()->json(['error' => 'Unauthorized'], 403);

        $input = $this->getInput($request);

        $validator = \Illuminate\Support\Facades\Validator::make($input, [
            'body' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $body = $input['body'] ?? null;

        if (!$body || trim($body) === '') {
            return response()->json(['errors' => ['body' => ['The body field is required.']]], 422);
        }

        $parent = Message::whereNull('parent_id')
            ->where(function ($q) use ($sender) {
                $q->where(function ($q2) use ($sender) {
                    $q2->where('recipient_type', $sender['type'])
                       ->where('recipient_id', $sender['model']->id);
                })->orWhere(function ($q2) use ($sender) {
                    $q2->where('sender_type', $sender['type'])
                       ->where('sender_id', $sender['model']->id);
                });
            })
            ->findOrFail($parentId);

        $replyRecipientType = ($parent->sender_type === $sender['type'] && $parent->sender_id == $sender['model']->id)
            ? $parent->recipient_type
            : $parent->sender_type;
        $replyRecipientId = ($parent->sender_type === $sender['type'] && $parent->sender_id == $sender['model']->id)
            ? $parent->recipient_id
            : $parent->sender_id;

        $reply = Message::create([
            'body'           => $body,
            'sender_type'    => $sender['type'],
            'sender_id'      => $sender['model']->id,
            'recipient_type' => $replyRecipientType,
            'recipient_id'   => $replyRecipientId,
            'parent_id'      => $parentId,
        ]);

        AuditLogger::log('message_replied', 'messages', $reply->id, null, [
            'from'   => $sender['model']->name,
            'thread' => $parentId,
        ]);

        $replyRecipient = $replyRecipientType === 'user'
            ? User::find($replyRecipientId)
            : Visitor::find($replyRecipientId);

        // Defer the email/SMS so the reply POST returns without waiting on the
        // mail/SMS gateways.
        app()->terminating(function () use ($replyRecipient, $reply, $parent, $body, $sender) {
            if ($replyRecipient && $replyRecipient->email) {
                try {
                    Mail::to($replyRecipient->email)->send(new VmsNotificationMail(
                        'New Reply', $parent->subject ?: 'Reply from ' . $sender['model']->name,
                        $body, $replyRecipient->name, ['from_user' => $sender['model']->name]
                    ));
                    $reply->update(['email_sent' => true]);
                } catch (\Exception $e) {
                    Log::error("Reply email failed: " . $e->getMessage());
                }
            }

            if ($replyRecipient && $replyRecipient->phone) {
                try {
                    SmsService::send($replyRecipient->phone, "[VMS] From " . $this->smsSenderLabel($sender) . ": " . \Illuminate\Support\Str::limit($body, 160));
                    $reply->update(['sms_sent' => true]);
                } catch (\Exception $e) {
                    Log::error("Reply SMS failed: " . $e->getMessage());
                }
            }
        });

        return response()->json(['success' => true, 'reply_id' => $reply->id]);
    }

    public function destroy(Request $request, $id)
    {
        $sender = $this->getSender();
        if (!$sender) return response()->json(['error' => 'Unauthorized'], 403);

        $message = Message::findOrFail($id);

        if ($message->sender_type === $sender['type'] && $message->sender_id == $sender['model']->id) {
            $message->update(['sender_deleted' => true]);
        } elseif ($message->recipient_type === $sender['type'] && $message->recipient_id == $sender['model']->id) {
            $message->update(['recipient_deleted' => true, 'is_read' => true, 'read_at' => now()]);
        } else {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['success' => true]);
    }

    public function markRead($id)
    {
        $sender = $this->getSender();
        if (!$sender) abort(403);

        $message = Message::where('recipient_type', $sender['type'])
            ->where('recipient_id', $sender['model']->id)
            ->findOrFail($id);

        $message->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function unreadCount()
    {
        $sender = $this->getSender();
        if (!$sender) return response()->json(['count' => 0]);

        return response()->json(['count' => Message::unreadCount($sender['type'], $sender['model']->id)]);
    }

    public function recipients()
    {
        $sender = $this->getSender();
        if (!$sender) abort(403);

        return response()->json(self::recipientsFor($sender['type'], $sender['model']));
    }

    /**
     * Resolve the messaging scope (company + branch) of the sender.
     * A visitor's branch is derived from their most recent visit request,
     * since the visitors table has no branch column.
     */
    public static function senderScope(string $type, $model): array
    {
        if ($type === 'visitor') {
            return [
                'company_id' => $model->company_id ? (int)$model->company_id : null,
                'branch_id'  => self::visitorBranchId($model),
            ];
        }
        return [
            'company_id' => $model->company_id ? (int)$model->company_id : null,
            'branch_id'  => $model->branch_id ? (int)$model->branch_id : null,
        ];
    }

    /**
     * The branch a visitor belongs to = the branch of their latest visit request.
     */
    public static function visitorBranchId($visitor): ?int
    {
        $branchId = VisitRequest::where('visitor_id', $visitor->id)
            ->whereNotNull('branch_id')
            ->orderByDesc('id')
            ->value('branch_id');

        return $branchId ? (int)$branchId : null;
    }

    /**
     * Recipient list for a sender, scoped to the same company AND branch:
     * active users of that company/branch plus visitors who have visited that branch.
     * When the sender has no company the scope stays open (system admin sees everyone).
     */
    public static function recipientsFor(string $type, $model): \Illuminate\Support\Collection
    {
        $scope = self::senderScope($type, $model);
        $companyId = $scope['company_id'];
        $branchId = $scope['branch_id'];

        $users = User::query()
            ->where('status', 'active')
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($type === 'user', fn($q) => $q->where('id', '!=', $model->id))
            ->select('id', 'name', 'role', 'phone', 'email')
            ->get()
            ->map(fn($u) => [
                'id'    => (int)$u->id,
                'type'  => 'user',
                'name'  => $u->name,
                'role'  => $u->role,
                'phone' => $u->phone,
                'email' => $u->email,
            ]);

        $branchVisitorIds = [];
        if ($companyId && $branchId) {
            $branchVisitorIds = VisitRequest::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->pluck('visitor_id')
                ->unique()
                ->values();
        }

        $visitors = Visitor::query()
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->when($type === 'visitor', fn($q) => $q->where('id', '!=', $model->id))
            ->when($branchId, fn($q) => $q->whereIn('id', $branchVisitorIds))
            ->select('id', 'name', 'phone', 'email')
            ->get()
            ->map(fn($v) => [
                'id'    => (int)$v->id,
                'type'  => 'visitor',
                'name'  => $v->name,
                'role'  => 'visitor',
                'phone' => $v->phone,
                'email' => $v->email,
            ]);

        return $users->concat($visitors)->values();
    }

    /**
     * Whether a recipient may be messaged by the sender (same company + branch).
     */
    public static function recipientInScope(string $senderType, $senderModel, string $recipientType, $recipientModel): bool
    {
        $scope = self::senderScope($senderType, $senderModel);
        $companyId = $scope['company_id'];
        $branchId = $scope['branch_id'];

        if ($recipientType === 'user') {
            if ($companyId && $recipientModel->company_id && (int)$recipientModel->company_id !== $companyId) {
                return false;
            }
            if ($branchId && (int)$recipientModel->branch_id !== $branchId) {
                return false;
            }
            return true;
        }

        if ($companyId && $recipientModel->company_id && (int)$recipientModel->company_id !== $companyId) {
            return false;
        }
        if ($branchId && self::visitorBranchId($recipientModel) !== $branchId) {
            return false;
        }
        return true;
    }

    /**
     * Inbox list shaped for the message UI (sender name/phone/email, readable dates).
     */
    public static function inboxList(string $type, $id, int $take = 20): array
    {
        return Message::inbox($type, $id)
            ->with('sender')
            ->withCount('replies')
            ->latest('created_at')
            ->take($take)
            ->get()
            ->map(fn($msg) => [
                'id'           => (int)$msg->id,
                'subject'      => $msg->subject ?: '(No subject)',
                'body'         => $msg->body,
                'sender_name'  => $msg->sender->name ?? 'Unknown',
                'sender_phone' => $msg->sender->phone ?? null,
                'sender_email' => $msg->sender->email ?? null,
                'sender_type'  => $msg->sender_type,
                'sender_id'    => (int)$msg->sender_id,
                'created_at'   => $msg->created_at->format('M d, Y H:i'),
                'is_read'      => $msg->is_read,
                'reply_count'  => (int)$msg->replies_count,
            ])
            ->all();
    }

    /**
     * Sent list shaped for the message UI (recipient name/phone/email, readable dates).
     */
    public static function sentList(string $type, $id, int $take = 20): array
    {
        return Message::sent($type, $id)
            ->with('recipient')
            ->withCount('replies')
            ->latest('created_at')
            ->take($take)
            ->get()
            ->map(fn($msg) => [
                'id'              => (int)$msg->id,
                'subject'         => $msg->subject ?: '(No subject)',
                'body'            => $msg->body,
                'recipient_name'  => $msg->recipient->name ?? 'Unknown',
                'recipient_phone' => $msg->recipient->phone ?? null,
                'recipient_email' => $msg->recipient->email ?? null,
                'recipient_type'  => $msg->recipient_type,
                'recipient_id'    => (int)$msg->recipient_id,
                'created_at'      => $msg->created_at->format('M d, Y H:i'),
                'is_read'         => $msg->is_read,
                'reply_count'     => (int)$msg->replies_count,
            ])
            ->all();
    }
}
