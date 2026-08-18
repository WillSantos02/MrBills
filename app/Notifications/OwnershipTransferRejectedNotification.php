<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class OwnershipTransferRejectedNotification extends Notification
{
    protected string $declinedByName;

    public function __construct(User $declinedBy)
    {
        $this->declinedByName = $declinedBy->name;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'declined_by_name' => $this->declinedByName,
            'message' => "{$this->declinedByName} recusou se tornar titular. Você ainda pode excluir sua conta: os dados ficarão preservados enquanto a família existir.",
        ];
    }
}
