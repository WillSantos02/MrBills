<?php

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Notifications\BillDueSoonNotification;
use Illuminate\Console\Command;

class SendBillDueSoonNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-bill-due-soon';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify users about pending bills due within the next 3 days';

    public function handle(): int
    {
        $today = today();
        $windowEnd = $today->copy()->addDays(3);

        // Dedupe via a column on the bill itself (not the notifications table): the
        // notification is queued (ShouldQueue/RabbitMQ), so its "database" row may not
        // exist yet by the time this command finishes — checking notifications would race.
        $bills = Bill::with('user')
            ->where('status', BillStatus::Pendente)
            ->whereBetween('actual_due_date', [$today->toDateString(), $windowEnd->toDateString()])
            ->where(function ($query) use ($today) {
                $query->whereNull('last_due_soon_notified_at')
                    ->orWhere('last_due_soon_notified_at', '<', $today->toDateString());
            })
            ->get();

        foreach ($bills as $bill) {
            $bill->user->notify(new BillDueSoonNotification($bill));
            $bill->update(['last_due_soon_notified_at' => $today->toDateString()]);
        }

        $this->info("Sent {$bills->count()} bill-due-soon notification(s).");

        return self::SUCCESS;
    }
}
