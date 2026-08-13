<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class FamilyInviteAcceptedNotification extends Notification
{
    protected string $memberName;

    public function __construct(User $member)
    {
        $this->memberName = $member->name;
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
            'member_name' => $this->memberName,
            'message' => "{$this->memberName} aceitou seu convite e agora faz parte da sua família.",
        ];
    }
}
