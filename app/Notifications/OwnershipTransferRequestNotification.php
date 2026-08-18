<?php

namespace App\Notifications;

use App\Models\OwnershipTransferRequest;
use Illuminate\Notifications\Notification;

class OwnershipTransferRequestNotification extends Notification
{
    protected int $transferRequestId;

    protected string $fromUserName;

    public function __construct(OwnershipTransferRequest $transferRequest)
    {
        $this->transferRequestId = $transferRequest->id;
        $this->fromUserName = $transferRequest->fromUser->name;
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
            'transfer_request_id' => $this->transferRequestId,
            'from_user_name' => $this->fromUserName,
            'message' => "{$this->fromUserName} quer transferir a titularidade da família para você. Aceitar significa que todas as contas, receitas e categorias hoje registradas por {$this->fromUserName} passam a ser suas.",
        ];
    }
}
