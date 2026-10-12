<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VmsNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $event;
    public string $title;
    public string $message;
    public string $recipientName;
    public array $data;
    public array $emailAttachments = [];

    public function __construct(string $event, string $title, string $message, string $recipientName, array $data = [])
    {
        $this->event = $event;
        $this->title = $title;
        $this->message = $message;
        $this->recipientName = $recipientName;
        $this->data = $data;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[VMS] {$this->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    public function buildHtml(): string
    {
        $dataRows = '';
        foreach ($this->data as $key => $value) {
            if (is_scalar($value)) {
                $label = ucwords(str_replace('_', ' ', $key));
                $dataRows .= "<tr><td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:500;'>{$label}</td><td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#111827;'>{$value}</td></tr>";
            }
        }

        return "
        <!DOCTYPE html>
        <html>
        <head><meta charset='utf-8'></head>
        <body style='margin:0;padding:0;background:#f3f4f6;font-family:system-ui,-apple-system,sans-serif;'>
            <div style='max-width:600px;margin:40px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);'>
                <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:32px;text-align:center;'>
                    <h1 style='color:#ffffff;margin:0;font-size:20px;'>Visitor Management System</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:8px 0 0;font-size:14px;'>{$this->event}</p>
                </div>
                <div style='padding:32px;'>
                    <p style='color:#374151;font-size:15px;line-height:1.6;margin:0 0 16px;'>Dear {$this->recipientName},</p>
                    <p style='color:#374151;font-size:15px;line-height:1.6;margin:0 0 24px;'>{$this->message}</p>
                    " . ($dataRows ? "<table style='width:100%;border-collapse:collapse;margin-bottom:24px;'>{$dataRows}</table>" : "") . "
                </div>
                <div style='background:#f9fafb;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb;'>
                    <p style='color:#9ca3af;font-size:12px;margin:0;'>Visitor Management System - Automated Notification</p>
                </div>
            </div>
        </body>
        </html>";
    }

    public function attachments(): array
    {
        $attachments = [];
        foreach (($this->data['attachments'] ?? []) as $a) {
            if (isset($a['raw'], $a['name'], $a['mime'])) {
                $attachments[] = \Illuminate\Mail\Mailables\Attachment::fromData(
                    fn () => $a['raw'],
                    $a['name']
                )->withMime($a['mime']);
            }
        }
        return $attachments;
    }
}
