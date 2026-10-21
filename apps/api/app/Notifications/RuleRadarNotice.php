<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The email form of an in-app notification. No document text or evidence is included. */
class RuleRadarNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->title} | {$this->organizationName}")
            ->line($this->title);

        if ($this->body) {
            $mail->line($this->body);
        }
        if ($this->url) {
            $mail->action('Open in RuleRadar', $this->url);
        }

        return $mail->line('You can turn off emails like this in Organization settings.');
    }
}
