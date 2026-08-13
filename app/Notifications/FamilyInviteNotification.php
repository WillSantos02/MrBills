<?php

namespace App\Notifications;

use App\Models\FamilyInvite;
use Illuminate\Notifications\Notification;

class FamilyInviteNotification extends Notification
{
    protected int $familyInviteId;

    protected string $ownerName;

    public function __construct(FamilyInvite $familyInvite)
    {
        $this->familyInviteId = $familyInvite->id;
        $this->ownerName = $familyInvite->owner->name;
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
            'family_invite_id' => $this->familyInviteId,
            'owner_name' => $this->ownerName,
            'message' => "Você foi convidado(a) para fazer parte da família de {$this->ownerName}.",
        ];
    }
}
