<?php

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Models\Bill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CarryOverOverdueInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credit-cards:carry-over-overdue-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move the balance of unpaid, past-due credit card invoices into the next invoice';

    public function handle(): int
    {
        // Mais antigas primeiro: se o job ficou dias parado e há várias faturas vencidas em sequência, o saldo
        // de uma entra na seguinte, que por sua vez é transportada já com esse valor.
        $invoiceIds = Bill::whereNotNull('credit_card_id')
            ->where('status', BillStatus::Pendente->value)
            ->whereDate('actual_due_date', '<', today()->toDateString())
            ->whereDoesntHave('carriedPurchase')
            ->orderBy('due_date')
            ->pluck('id');

        $count = 0;

        foreach ($invoiceIds as $invoiceId) {
            DB::transaction(function () use ($invoiceId, &$count) {
                // Recarregado dentro do loop: o valor pode ter mudado pelo transporte da fatura anterior.
                $invoice = Bill::with('creditCard')->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

                if ($invoice->status !== BillStatus::Pendente || $invoice->creditCard === null) {
                    return;
                }

                $invoice->creditCard->carryOverOverdueInvoice($invoice);
                $count++;
            });
        }

        $this->info("Carried over {$count} overdue invoice(s).");

        return self::SUCCESS;
    }
}
