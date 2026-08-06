<?php

namespace App\Notifications;

use App\Models\Bill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class BillDueSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected int $billId;

    protected string $description;

    protected string $dueDate;

    protected int $daysUntilDue;

    public function __construct(Bill $bill)
    {
        $this->billId = $bill->id;
        $this->description = $bill->display_description;
        $this->dueDate = $bill->actual_due_date->toDateString();
        $this->daysUntilDue = (int) now()->startOfDay()->diffInDays($bill->actual_due_date->startOfDay(), false);
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
            'bill_id' => $this->billId,
            'description' => $this->description,
            'due_date' => $this->dueDate,
            'days_until_due' => $this->daysUntilDue,
            'message' => "A conta {$this->description} vencerá em {$this->daysUntilDue} dias.",
        ];
    }
}
