<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\VisitRequest;
use App\Models\User;

class HostTransferNotification extends Notification
{
    protected $visitRequest;
    protected $role; // 'previous' or 'new'
    protected $otherHost;
    protected $transferredBy;
    protected $otherHostId;

    public function __construct(VisitRequest $visitRequest, string $role, ?User $otherHost, User $transferredBy, int $otherHostId)
    {
        $this->visitRequest = $visitRequest;
        $this->role = $role;
        $this->otherHost = $otherHost;
        $this->transferredBy = $transferredBy;
        $this->otherHostId = $otherHostId;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $subject = $this->role === 'previous'
            ? 'You have been removed as host for a visit'
            : 'You have been assigned as a new host for a visit';

        $visitorName = $this->visitRequest->visitor->name ?? $this->visitRequest->visitor_name ?? 'the visitor';
        $otherHostName = $this->otherHost->name ?? 'another host';

        $message = $this->role === 'previous'
            ? "The visit for {$visitorName} has been transferred to {$otherHostName} by {$this->transferredBy->name}."
            : "The visit for {$visitorName} has been transferred to you by {$this->transferredBy->name}. Please review the visit details.";

        return (new MailMessage)
            ->subject($subject)
            ->line($message)
            ->action('View Visit', url('/visits/' . $this->visitRequest->id))
            ->line('Thank you for using our system.');
    }

    public function toArray($notifiable)
    {
        $visitorName = $this->visitRequest->visitor->name ?? $this->visitRequest->visitor_name ?? 'a visitor';

        return [
            'visit_request_id' => $this->visitRequest->id,
            'visitor_name' => $visitorName,
            'role' => $this->role,
            'other_host_id' => $this->otherHostId,
            'transferred_by' => $this->transferredBy->name,
            'message' => $this->role === 'previous'
                ? "You are no longer the host for {$visitorName}."
                : "You are now the host for {$visitorName}.",
        ];
    }
}
