<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class OwnershipTransferAcceptedNotification extends Notification
{
    protected string $newOwnerName;

    public function __construct(User $newOwner)
    {
        $this->newOwnerName = $newOwner->name;
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
            'new_owner_name' => $this->newOwnerName,
            'message' => "{$this->newOwnerName} aceitou se tornar o(a) titular da família. Você já pode excluir sua conta com segurança.",
        ];
    }
}
