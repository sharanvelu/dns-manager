<?php

declare(strict_types = 1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    /** @return array<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire');

        return (new MailMessage())
            ->subject('Reset your ' . config('app.name') . ' password')
            ->line('We received a request to reset the password for your account.')
            ->action('Reset password', route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line("This link expires in {$minutes} minutes. If you did not request it, you can ignore this email.");
    }
}
