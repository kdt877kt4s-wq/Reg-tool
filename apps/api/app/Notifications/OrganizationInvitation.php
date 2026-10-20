<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class OrganizationInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $roleLabel,
        public readonly string $url,
        public readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Join {$this->organizationName} on RuleRadar")
            ->line("{$this->inviterName} invited you to join {$this->organizationName} on RuleRadar as {$this->roleLabel}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires on '.$this->expiresAt->format('F j, Y').'.')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
