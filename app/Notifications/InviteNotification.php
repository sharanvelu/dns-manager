<?php

declare(strict_types = 1);

namespace App\Notifications;

use App\Support\AuthMethods;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class InviteNotification extends Notification implements ShouldQueue
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
        $hours = (int) round((int) config('auth.passwords.invites.expire') / 60);

        $mail = (new MailMessage())
            ->subject('You have been invited to ' . config('app.name'))
            ->line('An account has been created for you. Set a password to finish setting it up.')
            ->action('Create your password', route('password.invite', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line("This link expires in {$hours} hours.");

        if (AuthMethods::sso()) {
            $mail->line('You can also sign in with single sign-on using this email address.');
        }

        return $mail;
    }
}
