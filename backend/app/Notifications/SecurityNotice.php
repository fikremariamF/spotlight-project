<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email sent when a sign-in detail changes, so the owner notices a change they did not make.
 * Always sent, whatever the user's alert settings say.
 */
class SecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** @param  'email_changed'|'password_changed'  $kind */
    public function __construct(public string $kind, public string $name, public ?string $newEmail = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('marketplace.name');
        $mail = (new MailMessage)->greeting('Hello '.$this->name.',');

        if ($this->kind === 'email_changed') {
            $mail->subject("Your {$brand} email address was changed")
                ->line("The email address on your {$brand} account was changed to {$this->newEmail}. Emails will go there from now on.");
        } else {
            $mail->subject("Your {$brand} password was changed")
                ->line("The password for your {$brand} account was changed, and every other device was signed out.");
        }

        return $mail->line('If you did not do this, reply to this email or contact us right away.')
            ->salutation('Thank you, '.$brand);
    }
}
