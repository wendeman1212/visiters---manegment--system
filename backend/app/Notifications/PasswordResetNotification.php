<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordResetNotification extends Notification
{
    public $otp;
    public $email;

    public function __construct($otp, $email)
    {
        $this->otp = $otp;
        $this->email = $email;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Password Reset Verification Code - VMS')
            ->greeting('Hello!')
            ->line('We received a request to reset your password. Use the verification code below to choose a new password.')
            ->line('Your verification code is: ' . $this->otp)
            ->line('This code will expire in 15 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
