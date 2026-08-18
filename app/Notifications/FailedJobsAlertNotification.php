<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FailedJobsAlertNotification extends Notification
{
    public function __construct(protected int $count) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Mr. Bills: {$this->count} job(s) falharam na fila")
            ->line("Foram detectados {$this->count} novo(s) job(s) na tabela failed_jobs desde a última checagem.")
            ->line('Rode `php artisan queue:failed` no servidor para investigar.');
    }
}
