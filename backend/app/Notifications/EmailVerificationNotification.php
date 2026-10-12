<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class EmailVerificationNotification extends Notification
{
    public $code;
    public $email;

    public function __construct($code, $email)
    {
        $this->code = $code;
        $this->email = $email;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Verify Your Email Address - VMS')
            ->greeting('Hello!')
            ->line('Please confirm that this email address belongs to you so we can complete your account registration.')
            ->line('Your email verification code is: ' . $this->code)
            ->line('This code will expire in 15 minutes.')
            ->line('If you did not start a registration, you can safely ignore this email.');
    }
}
