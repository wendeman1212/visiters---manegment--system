<?php

namespace App\Mail;

use App\Models\VisitRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VisitorHostChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $visitRequest;
    public $previousHost;
    public $newHost;

    public function __construct(VisitRequest $visitRequest, User $previousHost, User $newHost)
    {
        $this->visitRequest = $visitRequest;
        $this->previousHost = $previousHost;
        $this->newHost = $newHost;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Host Has Been Changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.visitor-host-changed',
            with: [
                'visitorName' => $this->visitRequest->visitor->name ?? 'Visitor',
                'companyName' => $this->visitRequest->company_name ?? 'N/A',
                'appointmentDate' => $this->visitRequest->requested_time?->format('M d, Y H:i') ?? 'N/A',
                'previousHostName' => $this->previousHost->name,
                'newHostName' => $this->newHost->name,
                'purpose' => $this->visitRequest->purpose ?? 'N/A',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
