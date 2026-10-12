<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\VisitRequest;
use App\Models\User;

class VisitorHostChangedNotification extends Notification
{
    protected $visitRequest;
    protected $previousHost;
    protected $newHost;

    public function __construct(VisitRequest $visitRequest, User $previousHost, User $newHost)
    {
        $this->visitRequest = $visitRequest;
        $this->previousHost = $previousHost;
        $this->newHost = $newHost;
    }

    public function via($notifiable)
    {
        return ['mail', 'database']; // SMS can be added via the service
    }

    public function toMail($notifiable)
    {
        $appointmentDate = $this->visitRequest->requested_time
            ? $this->visitRequest->requested_time->format('M d, Y H:i')
            : 'scheduled';

        return (new MailMessage)
            ->subject('Your host has been changed')
            ->line("Your host for the visit on {$appointmentDate} has been changed.")
            ->line("Old host: {$this->previousHost->name}")
            ->line("New host: {$this->newHost->name}")
            ->line('Please contact the new host for any questions.')
            ->action('View Visit', url('/visits/' . $this->visitRequest->id))
            ->line('Thank you.');
    }

    public function toArray($notifiable)
    {
        return [
            'visit_request_id' => $this->visitRequest->id,
            'previous_host' => $this->previousHost->name,
            'new_host' => $this->newHost->name,
            'message' => "Your host has been changed from {$this->previousHost->name} to {$this->newHost->name}.",
        ];
    }
}
