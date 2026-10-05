<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Enums\CardColor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bank',
        'last_four_digits',
        'color',
        'credit_limit',
        'closing_day',
        'due_day',
    ];

    protected $casts = [
        'color' => CardColor::class,
        'closing_day' => 'integer',
        'due_day' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditCardPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(CreditCardPurchase::class);
    }

    /**
     * Faturas do cartão — cada uma é uma Bill comum, então aparece em Despesas e entra no Total a Pagar.
     *
     * @return HasMany<Bill, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function getInvoiceDescriptionAttribute(): string
    {
        return "Fatura {$this->bank} •••• {$this->last_four_digits}";
    }

    /**
     * Data de fechamento do ciclo em que uma compra feita em $date cai: compras até o dia do fechamento
     * (inclusive) entram no ciclo do próprio mês, depois dele, no do mês seguinte.
     */
    public function closingDateFor(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $closing = $this->dayOfMonth($date, $this->closing_day);

        if ($date->greaterThan($closing)) {
            $closing = $this->dayOfMonth($date->startOfMonth()->addMonthNoOverflow(), $this->closing_day);
        }

        return $closing;
    }

    /**
     * Vencimento nominal da fatura de uma compra feita em $date: o primeiro dia de vencimento depois do
     * fechamento do ciclo (mesmo mês se o vencimento for depois do fechamento, senão o mês seguinte).
     * $offsetMonths desloca para faturas futuras — usado pelas parcelas 2..N de uma compra parcelada.
     */
    public function invoiceDueDateFor(CarbonInterface $date, int $offsetMonths = 0): CarbonImmutable
    {
        $closing = $this->closingDateFor($date);

        if ($offsetMonths > 0) {
            $closing = $this->dayOfMonth($closing->startOfMonth()->addMonthsNoOverflow($offsetMonths), $this->closing_day);
        }

        $due = $this->dayOfMonth($closing, $this->due_day);

        if ($due->lessThanOrEqualTo($closing)) {
            $due = $this->dayOfMonth($closing->startOfMonth()->addMonthNoOverflow(), $this->due_day);
        }

        return $due;
    }

    /**
     * Inverso de invoiceDueDateFor(): o fechamento do ciclo que gera a fatura com esse vencimento.
     */
    public function closingDateForInvoiceDue(CarbonInterface $dueDate): CarbonImmutable
    {
        $due = CarbonImmutable::parse($dueDate)->startOfDay();
        $closing = $this->dayOfMonth($due, $this->closing_day);

        if ($closing->greaterThanOrEqualTo($due)) {
            $closing = $this->dayOfMonth($due->startOfMonth()->subMonthNoOverflow(), $this->closing_day);
        }

        return $closing;
    }

    /**
     * Fatura (Bill) do ciclo de uma compra feita em $date, criada na hora se ainda não existir.
     */
    public function invoiceFor(CarbonInterface $date, int $offsetMonths = 0): Bill
    {
        return Bill::firstOrCreate(
            [
                'credit_card_id' => $this->id,
                'due_date' => $this->invoiceDueDateFor($date, $offsetMonths)->toDateString(),
            ],
            [
                'user_id' => $this->user_id,
                'description' => $this->invoice_description,
                'value' => 0,
                'status' => BillStatus::Pendente->value,
                'is_recurrent' => false,
                'total_installments' => 1,
                'current_installments' => 1,
            ],
        );
    }

    /**
     * Fatura do ciclo aberto hoje (a que recebe uma compra feita agora), se já houver alguma compra nela.
     */
    public function currentInvoice(): ?Bill
    {
        return $this->invoices()
            ->whereDate('due_date', $this->invoiceDueDateFor(now())->toDateString())
            ->first();
    }

    /**
     * Fatura já fechada e ainda não paga (ciclo anterior ao atual), se houver.
     */
    public function closedPendingInvoice(): ?Bill
    {
        return $this->invoices()
            ->where('status', BillStatus::Pendente->value)
            ->whereDate('due_date', '<', $this->invoiceDueDateFor(now())->toDateString())
            ->orderByDesc('due_date')
            ->first();
    }

    /**
     * Limite em uso: tudo que está em faturas ainda pendentes, incluindo parcelas futuras (a compra
     * parcelada ocupa o valor total de uma vez, como no banco) e saldos transportados. Faturas pagas
     * liberam o limite; faturas renegociadas também, porque o saldo delas já foi lançado na seguinte.
     */
    public function usedLimit(): float
    {
        return (float) $this->purchases()
            ->whereHas('bill', fn ($q) => $q->where('status', BillStatus::Pendente->value))
            ->sum('value');
    }

    public function availableLimit(): ?float
    {
        return $this->credit_limit === null ? null : (float) $this->credit_limit - $this->usedLimit();
    }

    /**
     * Valor em aberto de faturas que venceram sem ser pagas — seja já transportado para uma fatura
     * pendente, seja uma fatura vencida que o job diário ainda não transportou. > 0 = aviso FATURA PENDENTE.
     */
    public function overdueBalance(): float
    {
        $carried = $this->purchases()
            ->whereNotNull('carried_from_bill_id')
            ->whereHas('bill', fn ($q) => $q->where('status', BillStatus::Pendente->value))
            ->sum('value');

        $notCarriedYet = $this->invoices()
            ->where('status', BillStatus::Pendente->value)
            ->whereDate('actual_due_date', '<', today()->toDateString())
            ->sum('value');

        return (float) $carried + (float) $notCarriedYet;
    }

    /**
     * Dados que o visual do cartão (<x-credit-card>) mostra — usado na tela de Cartões e no dashboard.
     *
     * @return array{total: float, closing: CarbonImmutable, due: CarbonImmutable, used: float, overdue: float}
     */
    public function displaySummary(): array
    {
        return [
            'total' => (float) ($this->currentInvoice()->value ?? 0),
            'closing' => $this->closingDateFor(now()),
            'due' => $this->invoiceDueDateFor(now()),
            'used' => $this->usedLimit(),
            'overdue' => $this->overdueBalance(),
        ];
    }

    /**
     * Fatura venceu sem ser paga: vira "Renegociado" e o valor dela entra como lançamento na próxima fatura
     * ainda pendente. Assim o saldo não é contado duas vezes no Total a Pagar nem no limite.
     */
    public function carryOverOverdueInvoice(Bill $overdue): CreditCardPurchase
    {
        // Primeiro dia do ciclo seguinte ao da fatura vencida; pula ciclos cuja fatura já foi quitada.
        $date = $this->closingDateForInvoiceDue($overdue->due_date)->addDay();

        while ($this->invoices()
            ->whereDate('due_date', $this->invoiceDueDateFor($date)->toDateString())
            ->where('status', '!=', BillStatus::Pendente->value)
            ->exists()) {
            $date = $this->closingDateFor($date)->addDay();
        }

        $overdue->update(['status' => BillStatus::Renegociado->value]);

        return CreditCardPurchase::create([
            'user_id' => $this->user_id,
            'credit_card_id' => $this->id,
            'carried_from_bill_id' => $overdue->id,
            'description' => "Saldo da fatura anterior (venc. {$overdue->actual_due_date->format('d/m/Y')})",
            'value' => $overdue->value,
            'purchase_date' => $date->toDateString(),
        ]);
    }

    /**
     * Reaplica a regra de ciclo nas compras de faturas ainda pendentes — chamado depois de alterar o dia
     * de fechamento/vencimento. Faturas pagas ficam como estão (histórico); saldos transportados ficam na
     * fatura em que foram lançados.
     */
    public function resyncPendingInvoices(): void
    {
        $this->invoices()->update(['description' => $this->invoice_description]);

        $this->purchases()
            ->whereNull('carried_from_bill_id')
            ->whereHas('bill', fn ($q) => $q->where('status', BillStatus::Pendente->value))
            ->get()
            ->each(function (CreditCardPurchase $purchase) {
                $purchase->assignInvoice();
                $purchase->save();
            });
    }

    private function dayOfMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $month = $month->startOfMonth();

        return $month->setDay(min($day, $month->daysInMonth));
    }
}
